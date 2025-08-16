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
    ): bool { // retorna true se alguém morrer {
        $skillService = new SkillService();
        $globalMessages = [];
        $someoneDied = false; // flag para retornar

        try {
            // Garantir que sempre teremos um array de targets
            $targetsList = is_array($targets) && isset($targets[0]) ? $targets : [$targets];

            $casterName = $caster['name'] ?? ($caster['username'] ?? 'Desconhecido');
            $skillName = $skillService->getSkillName($skillId);

            $allResults = [];
            // NOVO: map instanceId => ['type' => 'character'|'monster', 'used_stamina_total' => float, 'current_stamina' => float|null]
            $staminaUpdates = [];
            $casterCurrentStamina = null;

            Log::channel('battle_debug')->info("[executeAction] Caster: $casterName usando skill $skillName", [
                'targets' => $targetsList
            ]);

            foreach ($targetsList as &$target) {
                Log::channel('battle_debug')->info("[executeAction] Target BEFORE applySkill", [
                    'targetId' => $target['instanceId'],
                    'targetType' => $targetType,
                    'targetData' => $target,
                    'casterId' => $caster['instanceId']
                ]);

                $result = $skillService->applySkill($caster, $target, $battleId, $skillId, $casterType, $targetType);

                Log::channel('battle_debug')->info("[executeAction] Result AFTER applySkill", [
                    'targetId' => $target['instanceId'],
                    'result' => $result
                ]);

                // captura current_stamina para broadcast depurativo (último valor válido)
                if (isset($result['current_stamina'])) {
                    $casterCurrentStamina = $result['current_stamina'];
                }

                // NOVO: Se o resultado trouxe used_stamina_total, armazena associado ao caster_id retornado
                // (anteriormente guardávamos initial_stamina; agora usamos used_stamina_total)
                if (isset($result['used_stamina_total'])) {
                    $resCasterId = (string)($result['caster_id'] ?? $caster['instanceId'] ?? '');
                    if ($resCasterId !== '') {
                        $staminaUpdates[$resCasterId] = [
                            'type' => $casterType, // usa o tipo do caster para diferenciar character/monster
                            'used_stamina_total' => (float)$result['used_stamina_total'],
                            // NOVO: salva também current_stamina retornado pelo SkillService (pós consumo) para broadcast imediato
                            'current_stamina' => isset($result['current_stamina']) ? (float)$result['current_stamina'] : null,
                        ];
                        Log::channel('battle_debug')->info("[executeAction] Stamina update registrado", [
                            'casterId' => $resCasterId,
                            'type' => $casterType,
                            'used_stamina_total' => $result['used_stamina_total'],
                            'current_stamina' => $result['current_stamina'] ?? null
                        ]);
                    }
                }

                $targetName = $target['name'] ?? ($target['username'] ?? 'Desconhecido');
                $targetTypeStr = ($targetType ?? 'character') === 'monster' ? 'Monstro' : 'Jogador';

                $actionInfoUse = $result['action_info_use'] ?? "$casterName usou $skillName";
                $actionInfoResult = $result['action_info_result'] ?? '';

                if (isset($result['damage_dealt'])) {
                    $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu dano de {$result['damage_dealt']}";
                } elseif (isset($result['healed_amount'])) {
                    $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu cura de {$result['healed_amount']}";
                } elseif (isset($result['buff_applied'])) {
                    $buff = $result['buff_applied'];
                    $actionInfoResult = "Buff aplicado: +{$buff['bonus']} {$buff['stat']} por {$buff['duration']} turnos";
                }

                if ($target && isset($target['hp']) && $target['hp'] <= 0) {
                    $globalMessages[] = "{$targetName} morreu!";
                    $someoneDied = true; // marca que alguém morreu
                }

                $allResults[] = [
                    'actionInfoUse' => $actionInfoUse,
                    'actionInfoResult' => $actionInfoResult
                ];

                Log::channel('battle_debug')->error("[executeAction] Result aplicado", [
                    'target' => $targetName,
                    'result' => $result
                ]);
            }

            // Estado atualizado dos personagens
            $playersPayload = [];

            foreach (Redis::hgetall("battle:$battleId:characters_data") as $playerId => $playerJson) {
                $playerData = json_decode($playerJson, true);

                // constrói o payload do player
                $playerPayload = [
                    'instanceId' => (string)$playerId,
                    'currentHp' => (int)($playerData['hp'] ?? 0),
                ];

                // NOVO: Se houver um update de used_stamina_total para esse player (do tipo 'character'), anexa staminaData
                $playerIdKey = (string)$playerId;
                if (isset($staminaUpdates[$playerIdKey]) && $staminaUpdates[$playerIdKey]['type'] === 'character') {
                    // tenta ler dados completos de stamina no Redis para fornecer start_time e initial_stamina ao cliente
                    $staminaHashKey = "battle:$battleId:stamina_data";
                    $staminaField = "character:{$playerIdKey}"; // campo usado no hash
                    $stRaw = Redis::hget($staminaHashKey, $staminaField);

                    if ($stRaw) {
                        $stParsed = json_decode($stRaw, true);
                        // NOVO: anexamos used_stamina_total (o cliente deve subtrair desse baseline) e os campos de sincronização
                        $playerPayload['staminaData'] = [
                            'initial_stamina' => (float)($stParsed['initial_stamina'] ?? 0),
                            'start_time' => (int)($stParsed['start_time'] ?? 0),
                            'used_stamina_total' => (float)$staminaUpdates[$playerIdKey]['used_stamina_total'],
                            // NOVO: opcionalmente incluímos current_stamina pós-consumo para exibição imediata
                            'current_stamina' => $staminaUpdates[$playerIdKey]['current_stamina'] ?? null,
                        ];

                        Log::channel('battle_debug')->info("[executeAction] Anexando staminaData (character) ao payload", [
                            'playerId' => $playerIdKey,
                            'staminaData' => $playerPayload['staminaData']
                        ]);
                    } else {
                        // Fallback: se não achamos o hash (improvável), enviamos ao menos o used_stamina_total
                        $playerPayload['staminaData'] = [
                            'used_stamina_total' => (float)$staminaUpdates[$playerIdKey]['used_stamina_total'],
                            'current_stamina' => $staminaUpdates[$playerIdKey]['current_stamina'] ?? null,
                        ];
                        Log::channel('battle_debug')->warning("[executeAction] stamina_data não encontrada no Redis para character; enviando fallback used_stamina_total", [
                            'playerId' => $playerIdKey,
                            'stamina_update' => $staminaUpdates[$playerIdKey]
                        ]);
                    }
                }

                $playersPayload[] = $playerPayload;
            }

            // Estado atualizado dos monstros
            $enemiesPayload = [];
            foreach (Redis::hgetall("battle:$battleId:monsters") as $monsterId => $monsterJson) {
                $monsterData = json_decode($monsterJson, true);

                // cria monster payload (NOVO: agora construímos o payload completo antes de push)
                $monsterPayload = [
                    'instanceId' => (string)$monsterId,
                    'isAlive' => isset($monsterData['hp']) && $monsterData['hp'] > 0,
                ];

                // NOVO: Se houver um update de used_stamina_total para esse monster, anexa staminaData
                $monsterIdKey = (string)$monsterId;
                if (isset($staminaUpdates[$monsterIdKey]) && $staminaUpdates[$monsterIdKey]['type'] === 'monster') {
                    $staminaHashKey = "battle:$battleId:stamina_data";
                    $staminaField = "monster:{$monsterIdKey}";
                    $stRaw = Redis::hget($staminaHashKey, $staminaField);

                    if ($stRaw) {
                        $stParsed = json_decode($stRaw, true);
                        $monsterPayload['staminaData'] = [
                            'initial_stamina' => (float)($stParsed['initial_stamina'] ?? 0),
                            'start_time' => (int)($stParsed['start_time'] ?? 0),
                            'used_stamina_total' => (float)$staminaUpdates[$monsterIdKey]['used_stamina_total'],
                            'current_stamina' => $staminaUpdates[$monsterIdKey]['current_stamina'] ?? null,
                        ];

                        Log::channel('battle_debug')->info("[executeAction] Anexando staminaData (monster) ao payload", [
                            'monsterId' => $monsterIdKey,
                            'staminaData' => $monsterPayload['staminaData']
                        ]);
                    } else {
                        // fallback
                        $monsterPayload['staminaData'] = [
                            'used_stamina_total' => (float)$staminaUpdates[$monsterIdKey]['used_stamina_total'],
                            'current_stamina' => $staminaUpdates[$monsterIdKey]['current_stamina'] ?? null,
                        ];
                        Log::channel('battle_debug')->warning("[executeAction] stamina_data não encontrada no Redis para monster; enviando fallback used_stamina_total", [
                            'monsterId' => $monsterIdKey,
                            'stamina_update' => $staminaUpdates[$monsterIdKey]
                        ]);
                    }
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

            // broadcast depurativo: staminacheck (apenas se conseguimos um valor de stamina)
            if ($casterCurrentStamina !== null) {
                $staminaPayload = [
                    'caster' => [
                        'instanceId' => (string)($caster['instanceId'] ?? ''),
                        'type' => $casterType,
                        'current_stamina' => $casterCurrentStamina,
                    ],
                ];

                //BattleBroadcaster::broadcastToBattle($battleId, $staminaPayload, 'staminacheck');
                Log::channel('battle_debug')->info("[BattleActions] Broadcast 'staminacheck' enviado para batalha $battleId", [
                    'staminaPayload' => $staminaPayload
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
