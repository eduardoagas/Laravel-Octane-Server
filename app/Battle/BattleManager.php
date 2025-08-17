<?php

namespace App\Battle;

use App\Battle\BattleActions as BattleBattleActions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use App\Services\Battle\BattleActions;
use App\Services\Battle\SkillService;

class BattleManager
{
    /* public function createBattle(string $battleId, array $battleData): void
    {
        Log::info("Creating battle $battleId with data:", ['battleData' => $battleData]);

        foreach ($battleData['monsters'] as $index => $monster) {
            Redis::hset("battle:$battleId:monsters", $index, json_encode($monster));
        }

        foreach ($battleData['characters'] as $charId => $character) {
            Redis::hset("battle:$battleId:characters_data", $charId, json_encode($character));
        }

        Redis::sadd('battles:active', $battleId);
        $this->updateLastUpdate($battleId);

        Log::info("Battle $battleId created and added to active battles.");
    }*/

    public function finishBattle(string $battleId): void
    {
        Redis::srem('battles:active', $battleId);

        $keys = [
            "battle:$battleId:users",
            "battle:$battleId:characters_data",
            "battle:$battleId:monsters",
            "battle:$battleId:stamina_data",
            "battle:$battleId:buffs",
            "battle:$battleId:last_update",
        ];

        Redis::del($keys);
        Log::info("Battle $battleId finalized and data cleaned.");
    }

    public function cleanupOldBattles(int $maxAgeSeconds = 3600): void
    {
        $now = time();
        $battleIds = Redis::smembers('battles:active');

        foreach ($battleIds as $battleId) {
            $lastUpdate = Redis::get("battle:$battleId:last_update");

            if (!$lastUpdate || ($now - (int)$lastUpdate) > $maxAgeSeconds) {
                $this->finishBattle($battleId);
            }
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

    public function processBattleUsers(string $battleId): bool
    {
        $playersRaw = Redis::hgetall("battle:$battleId:characters_data");
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");

        if (!$playersRaw) return false;

        $monsters = [];
        foreach ($monstersRaw as $k => $json) {
            $m = json_decode($json, true);
            if (!isset($m['instanceId'])) $m['instanceId'] = (string)$k;
            $monsters[$k] = $m;
        }

        $players = [];
        foreach ($playersRaw as $k => $json) {
            $p = json_decode($json, true);
            if (!isset($p['instanceId'])) $p['instanceId'] = (string)$k;
            $players[$k] = $p;
        }

        $pendingActionsKey = "battle:$battleId:pending_actions";
        $pendingActions = Redis::hgetall($pendingActionsKey);

        $processed = false;

        foreach ($pendingActions as $characterId => $actionJson) {
            $action = json_decode($actionJson, true);

            if (!isset($players[$characterId])) {
                Redis::hdel($pendingActionsKey, $characterId);
                continue;
            }

            $caster = &$players[$characterId];

            // LOG: ação recebida
            Log::channel('battle_debug')->info("[processBattleUsers] Processing action", [
                'characterId' => $characterId,
                'caster' => $caster,
                'action' => $action
            ]);

            $targets = $this->resolveTargets($action, $caster, $players, $monsters, 'character');

            // LOG: targets resolvidos
            Log::channel('battle_debug')->info("[processBattleUsers] Targets resolved", [
                'characterId' => $characterId,
                'targets' => $targets
            ]);

            try {
                $skillId = (int)($action['skill_id'] ?? 0);
                $battleActions = new \App\Battle\BattleActions();

                foreach ($targets as $t) {
                    $targetId = $t['refInstanceId'];
                    $targetKey = $t['ref_key'];

                    $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    $targetRef = $targetJson ? json_decode($targetJson, true) : null;
                    Log::channel('battle_debug')->info("[BattleManager before execute action] TARGETREF = " . $targetJson);
                    $someoneDied = $battleActions->executeAction($caster, $skillId, $targetRef, $battleId, 'character', $t['category']);


                    $freshJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    if ($freshJson) {
                        $fresh = json_decode($freshJson, true);
                        if ($targetKey === 'monsters') {
                            $monsters[$targetId] = $fresh;
                        } else {
                            $players[$targetId] = $fresh;
                        }
                    } else {
                        Log::warning("After applySkill, fresh entity missing in Redis", [
                            'battleId' => $battleId,
                            'targetKey' => $targetKey,
                            'targetId' => $targetId
                        ]);
                    }
                    if ($someoneDied) {
                        //$this->checkBattleEnd($battleId);
                    }
                }

                $processed = true;
            } catch (\Exception $e) {
                Log::error("Error processing action for player $characterId: " . $e->getMessage(), ['exception' => $e]);
            }
            // marca que terminou a execução
            Redis::del("battle:$battleId:skill_in_execution:$characterId");
            Redis::hdel($pendingActionsKey, $characterId);
        }

        return $processed;
    }

    public function processBattleMonsters(string $battleId): bool
    {
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");
        $playersRaw = Redis::hgetall("battle:$battleId:characters_data");

        if (!$monstersRaw || !$playersRaw) {
            Log::warning("[processBattleMonsters] Sem monstros ou jogadores na batalha $battleId");
            return false;
        }

        $monsters = [];
        foreach ($monstersRaw as $k => $json) {
            $m = json_decode($json, true);
            if (!isset($m['instanceId'])) $m['instanceId'] = (string)$k;
            $monsters[$k] = $m;
        }

        $players = [];
        foreach ($playersRaw as $k => $json) {
            $p = json_decode($json, true);
            if (!isset($p['instanceId'])) $p['instanceId'] = (string)$k;
            $players[$k] = $p;
        }

        $processed = false;

        foreach ($monsters as $monsterKey => &$monster) {
            $currentStamina = StaminaService::getCurrentStamina($battleId, (string)$monsterKey, 'monster');
            $monster['current_stamina'] = $currentStamina;

            Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) current stamina: $currentStamina");

            $behavior = $this->resolveBehavior($monster['type'] ?? '');
            if (!$behavior) {
                Log::warning("[processBattleMonsters] Behavior não encontrado para tipo {$monster['type']}");
                continue;
            }

            $action = $behavior->decideAction($monster, [
                'monsters' => $monsters,
                'players' => $players,
                'battle_id' => $battleId,
            ]);

            if (!$action) {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) não realizou nenhuma ação");
                continue;
            }

            // Checagem de stamina: se insuficiente, ignora ação
            $staminaCost = SkillService::getSkillStaminaCost($action['skill_id']);
            $requiredStamina = $staminaCost ?? 0;
            if ($currentStamina < $requiredStamina) {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) não tem stamina suficiente ({$currentStamina} < {$requiredStamina}), ação descartada");
                continue;
            }

            if (!isset($action['caster_id'])) {
                $action['caster_id'] = (string)$monsterKey;
            }
            if (!isset($action['caster_type'])) {
                $action['caster_type'] = 'monster';
            }


            $targets = $this->resolveTargets($action, $monster, $players, $monsters, 'monster');

            // LOG: targets resolvidos
            Log::channel('battle_debug')->info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) targets resolvidos", [
                'action' => $action,
                'targets' => $targets
            ]);

            if (empty($targets)) {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) não encontrou targets para o action", ['action' => $action]);
                continue;
            }

            try {
                $skillId = (int)($action['skill_id'] ?? 0);
                $battleActions = new \App\Battle\BattleActions();

                foreach ($targets as $t) {
                    $targetId = $t['refInstanceId'];
                    $targetKey = $t['ref_key'];

                    $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                    $someoneDied = $battleActions->executeAction($monster, $skillId, $targetRef, $battleId, 'monster', $t['category']);


                    $freshJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    if ($freshJson) {
                        $fresh = json_decode($freshJson, true);
                        if ($targetKey === 'monsters') {
                            $monsters[$targetId] = $fresh;
                        } else {
                            $players[$targetId] = $fresh;
                        }
                    } else {
                        Log::warning("After monster applySkill, fresh entity missing in Redis", [
                            'battleId' => $battleId,
                            'targetKey' => $targetKey,
                            'targetId' => $targetId
                        ]);
                    }
                    if ($someoneDied) {
                        //$this->checkBattleEnd($battleId);
                    }
                }

                $processed = true;
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) executed action $skillId");
            } catch (\Throwable $e) {
                Log::error("[processBattleMonsters] Erro ao processar ação do monstro {$monsterKey}: " . $e->getMessage(), ['exception' => $e]);
            }
        }

        return $processed;
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

    private function resolveTargets(array $action, array $caster, array $players, array $monsters, string $casterType): array
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


    public function checkBattleEnd(string $battleId): void
    {
        $playersRaw = Redis::hgetall("battle:$battleId:characters_data");
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");

        $allPlayersDead = true;
        foreach ($playersRaw as $playerJson) {
            $player = json_decode($playerJson, true);
            if (($player['hp'] ?? 0) > 0) {
                $allPlayersDead = false;
                break;
            }
        }

        $allMonstersDead = true;
        foreach ($monstersRaw as $monsterJson) {
            $monster = json_decode($monsterJson, true);
            if (($monster['hp'] ?? 0) > 0) {
                $allMonstersDead = false;
                break;
            }
        }

        if ($allPlayersDead) {
            Log::info("[BattleManager] Todos os jogadores morreram na batalha $battleId. Finalizando...");
            $this->finishBattle($battleId);
            return;
        }

        if ($allMonstersDead) {
            Log::info("[BattleManager] Todos os monstros morreram na batalha $battleId. Jogadores venceram!");
            $this->finishBattle($battleId);
            return;
        }
    }
}
