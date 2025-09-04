<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\BattleBroadcaster;

class BattleManagerHelpers

{

    /**
     * Remove ou decrementa stacks de um efeito (buff/debuff) de uma entidade
     *
     * @param string $battleId
     * @param string $entityType "character" ou "monster"
     * @param string $instanceId
     * @param string $effectType "buff" ou "debuff"
     * @param string $field Nome da key do efeito (ex: targetId:stat:casterId)
     * @param int $stacksToRemove Quantos stacks remover (default 1)
     * @param bool $forceDelete Ignora stacks e remove direto (ex: skill Remedy)
     */
    protected function handleEffectRemoval(
        string $battleId,
        string $entityType,
        string $instanceId,
        string $effectType,
        string $field,
        int $stacksToRemove = 1,
        bool $forceDelete = false
    ): void {
        $entityPrefix = $entityType === 'character' ? 'characters_data' : 'monsters';
        $hashKey = "battle:$battleId:{$entityPrefix}:{$instanceId}:" . ($effectType === 'buff' ? 'buffs' : 'debuffs');
        $indexKey = "battle:$battleId:{$entityPrefix}:{$instanceId}:" . ($effectType === 'buff' ? 'buff_index' : 'debuff_index');
        $ackKey   = "battle:$battleId:acks";

        $json = Redis::hget($hashKey, $field);
        if (!$json) return;

        $data = json_decode($json, true);
        if (!is_array($data)) {
            Redis::hdel($hashKey, $field);
            Redis::srem($indexKey, $field);
            return;
        }

        if ($forceDelete) {
            $data['stacks'] = 0;
        } else {
            $data['stacks'] = max(0, ($data['stacks'] ?? 1) - $stacksToRemove);
        }

        if ($data['stacks'] <= 0) {
            Redis::hdel($hashKey, $field);
            Redis::srem($indexKey, $field);
            // Registrar ACK
            $ackId = "{$effectType}_remove:{$entityType}:{$instanceId}:{$field}";
            $payload = [
                'ackId' => $ackId,
                'stacks' => $data['stacks'] ?? 0,
                'note' => null,

                //'type' => "{$effectType}_remove",
                //'timestamp' => time(),
            ];
            Redis::hset($ackKey, $ackId, json_encode($payload, JSON_UNESCAPED_UNICODE));

            Log::info("[BattleAcks] Registered ACK {$ackId}", ['payload' => $payload]);
        } else {
            // Mantém o efeito com stacks atualizados
            $data['duration'] = $data['duration'] ?? null; // opcional: resetar duração ou manter
            Redis::hset($hashKey, $field, json_encode($data, JSON_UNESCAPED_UNICODE));
        }


        Log::info("[BattleEffects] Removed/updated {$effectType} {$field} from {$entityType} {$instanceId}");
    }

    protected function notifyBattle(string $battleId, string $stage, array $context = []): void
    {
        $globalMessages = [];
        $actionInfoUse = '';
        $actionInfoResult = '';

        switch ($stage) {
            case 'pre_delay':
                $actionInfoUse = "{$context['caster_type']} {$context['caster_id']} começou a conjurar skill {$context['skill_id']}";
                $globalMessages[] = "Skill {$context['skill_id']} em preparação";
                break;

            case 'animation':
                $actionInfoUse = "{$context['caster_type']} {$context['caster_id']} está animando skill {$context['skill_id']}";
                $globalMessages[] = "Skill {$context['skill_id']} entrou na fase de animação";
                break;

            case 'result':
                // aqui você pode reaproveitar a lógica do executeAction para montar mensagens
                $actionInfoUse = $context['actionInfoUse'] ?? '';
                $actionInfoResult = $context['actionInfoResult'] ?? '';
                $globalMessages = $context['globalMessages'] ?? [];
                break;

            case 'error':
                $globalMessages = $context['errors'] ?? ["Erro inesperado"];
                break;
        }

        $updatePayload = [
            'players' => $context['players'] ?? [],
            'enemies' => $context['enemies'] ?? [],
            'general' => [
                'actionInfoUse' => $actionInfoUse,
                'actionInfoResult' => $actionInfoResult,
                'globalMessages' => $globalMessages,
            ],
        ];

        BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');

        Log::channel('battle_debug')->info("[notifyBattle] Broadcast stage={$stage}", [
            'battleId' => $battleId,
            'context' => $context,
        ]);
    }

    protected function finalizeSkillCast(string $battleId, array $event): bool
    {
        $skillService = new SkillService();
        $globalMessages = [];
        $allResults = [];
        $someoneDied = false;

        $caster = $event['caster'] ?? null;
        $targets = $event['targets'] ?? [];
        $casterType = $event['caster_type'] ?? 'character';
        $targetType = $event['target_type'] ?? 'character';
        $skillId = $event['skill_id'] ?? null;

        if (!$caster || !$skillId || empty($targets)) {
            $this->notifyBattle($battleId, 'error', ['errors' => ['Dados inválidos para finalizar skill']]);
            return false;
        }

        $targetsList = is_array($targets) && isset($targets[0]) ? $targets : [$targets];

        $staminaUpdates = [];
        $casterCurrentStamina = null;

        foreach ($targetsList as &$target) {
            // Aplica skill
            $result = $skillService->applySkill($caster, $target, $battleId, $skillId, $casterType, $targetType);

            // Atualiza stamina do caster se retornado
            if (array_key_exists('current_stamina', $result) && $result['current_stamina'] !== null) {
                $casterCurrentStamina = $result['current_stamina'];
            }

            // Atualiza stamina para payload
            if (isset($result['used_stamina_total'])) {
                $resCasterId = (string)($result['caster_id'] ?? '');
                if ($resCasterId !== '') {
                    $staminaUpdates[$resCasterId] = [
                        'type' => $casterType,
                        'used_stamina_total' => (float)$result['used_stamina_total'],
                        'current_stamina' => array_key_exists('current_stamina', $result) ? $result['current_stamina'] : null,
                    ];
                }
            }

            $targetName = $target['name'] ?? ($target['username'] ?? 'Desconhecido');
            $targetTypeStr = ($targetType ?? 'character') === 'monster' ? 'Monstro' : 'Jogador';
            $actionInfoUse = "{$casterType} {$caster['instanceId']} usou skill {$skillId}";
            $actionInfoResult = '';

            // Mensagens de resultado
            if (isset($result['damage_dealt'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu dano de {$result['damage_dealt']}";
            } elseif (isset($result['healed_amount'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu cura de {$result['healed_amount']}";
            } elseif (isset($result['buff_applied'])) {
                $buff = $result['buff_applied'];
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
                $roll = $result['debuff_roll'] ?? null;
                $actionInfoResult = "Debuff falhou em {$targetName}";
                $globalMessages[] = "❌ Debuff em {$targetName} falhou (chance: " . round(($chance ?? 0) * 100, 1) . "%, roll: {$roll})";
            }

            if (!empty($result['someoneDied']) || !empty($result['target_died'])) {
                $globalMessages[] = "{$targetName} morreu!";
                $someoneDied = true;
            }

            $allResults[] = [
                'actionInfoUse' => $actionInfoUse,
                'actionInfoResult' => $actionInfoResult
            ];
        }

        // Monta payloads de players
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

                $playerPayload['staminaData'] = [
                    'initial_stamina' => (float)($stParsed['initial_stamina'] ?? 0),
                    'start_time' => (int)($stParsed['start_time'] ?? 0),
                    'used_stamina_total' => (float)$staminaUpdates[$playerIdKey]['used_stamina_total'],
                    'current_stamina' => array_key_exists('current_stamina', $staminaUpdates[$playerIdKey]) ? $staminaUpdates[$playerIdKey]['current_stamina'] : null,
                ];
            }

            $playersPayload[] = $playerPayload;
        }

        // Monta payloads de monstros
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

        // Notifica clientes
        $this->notifyBattle($battleId, 'result', [
            'players' => $playersPayload,
            'enemies' => $enemiesPayload,
            'actionInfoUse' => implode(' | ', array_column($allResults, 'actionInfoUse')),
            'actionInfoResult' => implode(' | ', array_column($allResults, 'actionInfoResult')),
            'globalMessages' => $globalMessages,
        ]);

        // Log depurativo de stamina
        if ($casterCurrentStamina !== null) {
            Log::channel('battle_debug')->info("[finalizeSkillCast] Stamina atual do caster", [
                'instanceId' => $caster['instanceId'] ?? '',
                'current_stamina' => $casterCurrentStamina
            ]);
        }

        // Retorna se alguém morreu
        return $someoneDied;
    }



    /**
     * Adiciona 'name' ao payload de entidade (character/monster) se possível,
     * lendo diretamente do Redis (ou deixando um valor padrão).
     * Recebe array por referência.
     */
    protected function enrichEntityWithName(string $battleId, array &$entity): void
    {
        if (!is_array($entity)) return;

        $type = $entity['type'] ?? 'character';
        $instanceId = (string)($entity['instanceId'] ?? '');

        try {
            if ($type === 'monster') {
                $raw = Redis::hget("battle:$battleId:monsters", $instanceId);
                $e = $raw ? json_decode($raw, true) : null;
                $entity['name'] = $e['name'] ?? ($entity['name'] ?? 'Monstro');
            } else {
                $raw = Redis::hget("battle:$battleId:characters_data", $instanceId);
                $e = $raw ? json_decode($raw, true) : null;
                $entity['name'] = $e['name'] ?? ($entity['name'] ?? 'Jogador');
            }
        } catch (\Throwable $e) {
            $entity['name'] = $entity['name'] ?? ($type === 'monster' ? 'Monstro' : 'Jogador');
        }
    }




    /**
     * Resolve e monta o payload da entidade usado como caster/target para applySkill.
     * - Se $type === 'character' => lê "battle:<battleId>:characters_data" field $instanceId
     * - Se $type === 'monster' => lê "battle:<battleId>:monsters" field $instanceId
     *
     * Retorna array com: ['instanceId' => string, 'stats' => array|null, 'type' => 'character'|'monster']
     * (garante que stats seja array decodificado quando possível)
     */
    protected function resolveEntityForTick(string $battleId, string $type, string $instanceId): array
    {
        if ($type === 'monster') {
            $raw = Redis::hget("battle:$battleId:monsters", $instanceId);
            $entity = $raw ? json_decode($raw, true) : null;
            $stats = $entity['stats'] ?? [];
            if (is_string($stats)) $stats = json_decode($stats, true) ?: [];
            return [
                'instanceId' => (string)$instanceId,
                'stats' => $stats,
                'type' => 'monster'
            ];
        } else {
            // character (default)
            $raw = Redis::hget("battle:$battleId:characters_data", $instanceId);
            $entity = $raw ? json_decode($raw, true) : null;
            $stats = $entity['stats'] ?? [];
            if (is_string($stats)) $stats = json_decode($stats, true) ?: [];
            return [
                'instanceId' => (string)$instanceId,
                'stats' => $stats,
                'type' => 'character'
            ];
        }
    }
    public function updateLastUpdate(string $battleId): void
    {
        Redis::set("battle:$battleId:last_update", time());
    }

    public function getActiveBattles(): array
    {
        return Redis::smembers('battles:active');
    }

    protected function resolveBehavior(string $type)
    {
        $map = [
            'goblin' => \App\Battle\MonstersBehavior\GoblinBehavior::class,
            'orc' => \App\Battle\MonstersBehavior\OrcBehavior::class,
        ];

        if (isset($map[$type])) {
            return app($map[$type]);
        }
    }

    protected function resolveTargets(array $action, array $caster, array $players, array $monsters, string $casterType): array
    {
        $targets = [];
        $targetType = $action['target_type'] ?? '';
        $targetId = isset($action['target_id']) ? (string)$action['target_id'] : null;
        $casterInstanceId = $caster['instanceId'] ?? ($action['caster_id'] ?? null);

        $addTarget = function ($refKey, $instanceId) use (&$targets) {
            // Define o tipo genérico a partir da refKey
            $category = $refKey === 'monsters' ? 'monster' : 'character';
            $targets[] = [
                'ref_key' => $refKey,
                'refInstanceId' => $instanceId,
                'category' => $category
            ];
        };

        switch ($targetType) {
            case 'self':
                $addTarget($casterType === 'monster' ? 'monsters' : 'characters_data', $casterInstanceId);
                break;

            case 'enemy':
                if ($casterType === 'character') {
                    if ($targetId !== null && isset($monsters[$targetId])) {
                        $addTarget('monsters', $targetId);
                    } else {
                        $firstKey = key($monsters);
                        if ($firstKey !== null) $addTarget('monsters', $firstKey);
                    }
                } else {
                    if ($targetId !== null && isset($players[$targetId])) {
                        $addTarget('characters_data', $targetId);
                    } else {
                        $firstKey = key($players);
                        if ($firstKey !== null) $addTarget('characters_data', $firstKey);
                    }
                }
                break;

            case 'ally':
                if ($casterType === 'character') {
                    if ($targetId !== null && isset($players[$targetId])) {
                        $addTarget('characters_data', $targetId);
                    }
                } else {
                    if ($targetId !== null && isset($monsters[$targetId])) {
                        $addTarget('monsters', $targetId);
                    }
                }
                break;

            case 'party':
                if ($casterType === 'character') {
                    foreach (array_keys($players) as $pId) {
                        $addTarget('characters_data', (string)$pId);
                    }
                } else {
                    foreach (array_keys($monsters) as $mId) {
                        $addTarget('monsters', (string)$mId);
                    }
                }
                break;

            case 'enemies':
                if ($casterType === 'character') {
                    foreach (array_keys($monsters) as $mId) {
                        $addTarget('monsters', (string)$mId);
                    }
                } else {
                    foreach (array_keys($players) as $pId) {
                        $addTarget('characters_data', (string)$pId);
                    }
                }
                break;

            default:
                if ($targetId !== null) {
                    if (isset($players[$targetId])) {
                        $addTarget('characters_data', $targetId);
                    } elseif (isset($monsters[$targetId])) {
                        $addTarget('monsters', $targetId);
                    }
                }
                break;
        }

        // LOG: Targets finais
        Log::channel('battle_debug')->info("[resolveTargets] Final targets", [
            'caster' => $caster['name'] ?? 'unknown',
            'targetType' => $targetType,
            'targets' => $targets
        ]);

        return $targets;
    }
}
