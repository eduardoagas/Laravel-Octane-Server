<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class BattleManagerHelpers
{
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