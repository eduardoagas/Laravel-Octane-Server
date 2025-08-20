<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\BattleBroadcaster;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class BattleActions
{
    public static function executeAction(
        array &$caster,
        int $skillId,
        $targets, // Pode ser um único alvo (array associativo) ou vários (array de arrays)
        string $battleId,
        string $casterType,
        string $targetType,
    ): bool {
        $skillService = new SkillService();
        $globalMessages = [];
        $someoneDied = false;

        try {
            // Garantir array de targets
            $targetsList = is_array($targets) && isset($targets[0]) ? $targets : [$targets];
            $casterId = (string)$caster['instanceId'];
            $casterName = $caster['name'] ?? ($caster['username'] ?? 'Desconhecido');
            $skillName = $skillService::getSkillName($skillId, $battleId, $casterType, $casterId);

            $allResults = [];
            $staminaUpdates = [];
            $casterCurrentStamina = null;

            Log::channel('battle_debug')->info("[executeAction] Caster: $casterName usando skill $skillName", [
                'targets' => $targetsList
            ]);

            foreach ($targetsList as &$target) {
                Log::channel('battle_debug')->info("[executeAction] Target BEFORE applySkill", [
                    'targetId' => $target['instanceId'] ?? null,
                    'targetType' => $targetType,
                    'targetData' => $target,
                    'casterId' => $caster['instanceId'] ?? null
                ]);

                Log::info("[debug] characters_data lookup", [
                    'key' => "battle:$battleId:characters_data",
                    'targetKey' => $target['instanceId'],
                    'exists' => Redis::hexists("battle:$battleId:characters_data", $target['instanceId'])
                ]);

                // Aplica skill (pode ser ação normal ou tick)
                $result = $skillService->applySkill($caster, $target, $battleId, $skillId, $casterType, $targetType);

                Log::channel('battle_debug')->info("[executeAction] Result AFTER applySkill", [
                    'targetId' => $target['instanceId'] ?? null,
                    'result' => $result
                ]);

                // === Tratamento de current_stamina nulo (ticks)
                // applySkill agora pode retornar 'current_stamina' === null para aplicações de tick.
                // Não sobrescrevemos $casterCurrentStamina com nulls — apenas atualizamos se houver valor não-nulo.
                if (array_key_exists('current_stamina', $result) && $result['current_stamina'] !== null) {
                    $casterCurrentStamina = $result['current_stamina'];
                }

                // === Registro de used_stamina_total (se houver)
                // Mantemos o registro apenas quando used_stamina_total estiver presente (isset)
                if (isset($result['used_stamina_total'])) {
                    $resCasterId = (string)($result['caster_id'] ?? '');
                    if ($resCasterId !== '') {
                        // current_stamina pode ser null (tick) e é aceitável — armazenamos mesmo assim
                        $staminaUpdates[$resCasterId] = [
                            'type' => $casterType,
                            'used_stamina_total' => (float)$result['used_stamina_total'],
                            'current_stamina' => array_key_exists('current_stamina', $result) ? $result['current_stamina'] : null,
                        ];
                    }
                }

                $targetName = $target['name'] ?? ($target['username'] ?? 'Desconhecido');
                $targetTypeStr = ($targetType ?? 'character') === 'monster' ? 'Monstro' : 'Jogador';

                $actionInfoUse = $result['action_info_use'] ?? "$casterName usou $skillName";
                $actionInfoResult = $result['action_info_result'] ?? '';

                // Ajustei mensagens para serem resilientes a mudanças nos campos retornados pelo Lua
                if (isset($result['damage_dealt'])) {
                    $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu dano de {$result['damage_dealt']}";
                } elseif (isset($result['healed_amount'])) {
                    $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu cura de {$result['healed_amount']}";
                } elseif (isset($result['buff_applied'])) {
                    $buff = $result['buff_applied'];
                    // bonus pode ter sido removido do model; caia para 'power' ou 0 para evitar erro
                    $buffValue = $buff['bonus'] ?? ($buff['power'] ?? 0);
                    $buffDuration = $buff['duration'] ?? '∞';
                    $buffStat = $buff['stat'] ?? 'unknown';
                    $actionInfoResult = "Buff aplicado: +{$buffValue} {$buffStat} por {$buffDuration} turnos";
                } elseif (isset($result['debuff_applied'])) {
                    $debuff = $result['debuff_applied'];
                    $debuffPower = $debuff['power'] ?? 0;
                    $debuffDuration = $debuff['duration'] ?? '∞';
                    $debuffStat = $debuff['stat'] ?? 'unknown';
                    $actionInfoResult = "Debuff aplicado em {$targetName}: -{$debuffStat} ({$debuffPower}) por {$debuffDuration} turnos";
                    $globalMessages[] = "⚡ Debuff de {$debuffStat} aplicado com sucesso em {$targetName}!";
                } elseif (!empty($result['debuff_failed'])) {
                    $chance = $result['debuff_chance'] ?? null;
                    $roll   = $result['debuff_roll'] ?? null;
                    $actionInfoResult = "Debuff falhou em {$targetName}";
                    $globalMessages[] = "❌ Debuff em {$targetName} falhou (chance: " . round(($chance ?? 0) * 100, 1) . "%, roll: {$roll})";

                    Log::channel('battle_debug')->warning("[executeAction] Debuff falhou", [
                        'caster' => $casterName,
                        'target' => $targetName,
                        'chance' => $chance,
                        'roll'   => $roll
                    ]);
                }

                // Usa sinal retornado pela skill para detectar morte (evita depender do estado local)
                if (!empty($result['someoneDied']) || !empty($result['target_died'])) {
                    $globalMessages[] = "{$targetName} morreu!";
                    $someoneDied = true;
                }

                $allResults[] = [
                    'actionInfoUse' => $actionInfoUse,
                    'actionInfoResult' => $actionInfoResult
                ];

                Log::channel('battle_debug')->info("[executeAction] Result aplicado", [
                    'target' => $targetName,
                    'result' => $result
                ]);
            }

            // === Monta payload atualizado dos players (inclui stamina se houver updates)
            $playersPayload = [];
            foreach (Redis::hgetall("battle:$battleId:characters_data") as $playerId => $playerJson) {
                $playerData = is_string($playerJson) ? json_decode($playerJson, true) ?? [] : $playerJson;
                $playerStats = $playerData['stats'] ?? [];
                if (is_string($playerStats)) $playerStats = json_decode($playerStats, true) ?: [];

                $playerPayload = [
                    'instanceId' => (string)$playerId,
                    'currentHp' => (int)($playerStats['current_hp'] ?? 0),
                ];

                $playerIdKey = (string)$playerId;
                if (isset($staminaUpdates[$playerIdKey]) && $staminaUpdates[$playerIdKey]['type'] === 'character') {
                    $stRaw = Redis::hget("battle:$battleId:stamina_data", "character:$playerIdKey");
                    $stParsed = is_string($stRaw) ? json_decode($stRaw, true) ?? [] : $stRaw;

                    // current_stamina pode ser null para ticks — propague esse null explicitamente
                    $playerPayload['staminaData'] = [
                        'initial_stamina' => (float)($stParsed['initial_stamina'] ?? 0),
                        'start_time' => (int)($stParsed['start_time'] ?? 0),
                        'used_stamina_total' => (float)$staminaUpdates[$playerIdKey]['used_stamina_total'],
                        'current_stamina' => array_key_exists('current_stamina', $staminaUpdates[$playerIdKey]) ? $staminaUpdates[$playerIdKey]['current_stamina'] : null,
                    ];
                }

                $playersPayload[] = $playerPayload;
            }

            // === Monta payload atualizado dos monstros (inclui stamina se houver updates)
            $enemiesPayload = [];
            foreach (Redis::hgetall("battle:$battleId:monsters") as $monsterId => $monsterJson) {
                $monsterData = is_string($monsterJson) ? json_decode($monsterJson, true) ?? [] : $monsterJson;
                $monsterStats = $monsterData['stats'] ?? [];
                if (is_string($monsterStats)) $monsterStats = json_decode($monsterStats, true) ?: [];

                $monsterPayload = [
                    'instanceId' => (string)$monsterId,
                    'isAlive' => ($monsterStats['current_hp'] ?? 0) > 0,
                ];

                $monsterIdKey = (string)$monsterId;
                if (isset($staminaUpdates[$monsterIdKey]) && $staminaUpdates[$monsterIdKey]['type'] === 'monster') {
                    $stRaw = Redis::hget("battle:$battleId:stamina_data", "monster:$monsterIdKey");
                    $stParsed = is_string($stRaw) ? json_decode($stRaw, true) ?? [] : $stRaw;

                    $monsterPayload['staminaData'] = [
                        'initial_stamina' => (float)($stParsed['initial_stamina'] ?? 0),
                        'start_time' => (int)($stParsed['start_time'] ?? 0),
                        'used_stamina_total' => (float)$staminaUpdates[$monsterIdKey]['used_stamina_total'],
                        'current_stamina' => array_key_exists('current_stamina', $staminaUpdates[$monsterIdKey]) ? $staminaUpdates[$monsterIdKey]['current_stamina'] : null,
                    ];
                }

                $enemiesPayload[] = $monsterPayload;
            }

            // Monta payload final
            $updatePayload = [
                'players' => $playersPayload,
                'enemies' => $enemiesPayload,
                'general' => [
                    'actionInfoUse' => implode(' | ', array_column($allResults, 'actionInfoUse')),
                    'actionInfoResult' => implode(' | ', array_column($allResults, 'actionInfoResult')),
                    'globalMessages' => $globalMessages,
                ],
            ];

            BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
            Log::info("[BattleActions] Broadcast enviado para batalha $battleId");

            // Broadcast depurativo
            if ($casterCurrentStamina !== null) {
                Log::channel('battle_debug')->info("[BattleActions] Stamina atual do caster", [
                    'instanceId' => $caster['instanceId'] ?? '',
                    'current_stamina' => $casterCurrentStamina
                ]);
            }
        } catch (InsufficientStaminaException $e) {
            self::broadcastError($battleId, ["Stamina insuficiente"]);
        } catch (SkillCooldownException $e) {
            self::broadcastError($battleId, ["Skill em cooldown"]);
        } catch (\Throwable $e) {
            Log::error("[BattleActions] Erro inesperado: " . $e->getMessage(), ['exception' => $e]);
            self::broadcastError($battleId, ["Erro inesperado ao executar ação"]);
        }

        return $someoneDied;
    }

    private static function broadcastError(string $battleId, array $messages): void
    {
        $updatePayload = [
            'players' => [],
            'enemies' => [],
            'general' => [
                'actionInfoUse' => '',
                'actionInfoResult' => '',
                'globalMessages' => $messages,
            ],
        ];
        BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
    }
}
