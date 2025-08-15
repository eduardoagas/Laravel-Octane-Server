<?php

namespace App\Battle;

use App\Battle\BattleActions as BattleBattleActions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use App\Services\Battle\BattleActions;

class BattleManager
{
    public function createBattle(string $battleId, array $battleData): void
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
    }

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

    // =========================
    // Processa ações dos jogadores
    // =========================
    public function processBattleUsers(string $battleId): bool
    {
        $playersRaw = Redis::hgetall("battle:$battleId:characters_data");
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");

        if (!$playersRaw) return false;

        $monsters = [];
        foreach ($monstersRaw as $k => $json) $monsters[$k] = json_decode($json, true);

        $players = [];
        foreach ($playersRaw as $k => $json) $players[$k] = json_decode($json, true);

        $pendingActionsKey = "battle:$battleId:pending_actions";
        $pendingActions = Redis::hgetall($pendingActionsKey);

        $processed = false;

        foreach ($pendingActions as $characterId => $actionJson) {
            $action = json_decode($actionJson, true);

            if (!isset($players[$characterId])) {
                // jogador não existe (talvez desconectado) — remove e segue
                Redis::hdel($pendingActionsKey, $characterId);
                continue;
            }

            $caster = &$players[$characterId];

            // Função para resolver alvos (AGORA sem depender de referências nessa lista)
            $resolveTargets = function (array $action, array $caster, array $players, array $monsters) {
                $targets = [];

                switch ($action['target_type'] ?? '') {
                    case 'self':
                        $targets[] = ['ref_key' => ($caster['type'] ?? 'character') === 'monster' ? 'monsters' : 'characters_data', 'id' => $caster['instanceId']];
                        break;

                    case 'enemy':
                        if (($caster['type'] ?? 'character') === 'character' && isset($action['target_id'], $monsters[$action['target_id']])) {
                            $targets[] = ['ref_key' => 'monsters', 'id' => $action['target_id']];
                        } elseif (($caster['type'] ?? 'character') === 'monster' && isset($action['target_id'], $players[$action['target_id']])) {
                            $targets[] = ['ref_key' => 'characters_data', 'id' => $action['target_id']];
                        }
                        break;

                    case 'ally':
                        if (($caster['type'] ?? 'character') === 'character' && isset($action['target_id'], $players[$action['target_id']])) {
                            $targets[] = ['ref_key' => 'characters_data', 'id' => $action['target_id']];
                        } elseif (($caster['type'] ?? 'character') === 'monster' && isset($action['target_id'], $monsters[$action['target_id']])) {
                            $targets[] = ['ref_key' => 'monsters', 'id' => $action['target_id']];
                        }
                        break;

                    case 'party':
                        if (($caster['type'] ?? 'character') === 'character') {
                            foreach ($players as $pId => $p) {
                                if ($pId !== $caster['instanceId']) $targets[] = ['ref_key' => 'characters_data', 'id' => $pId];
                            }
                        } else {
                            foreach ($monsters as $mId => $m) {
                                if ($mId !== $caster['instanceId']) $targets[] = ['ref_key' => 'monsters', 'id' => $mId];
                            }
                        }
                        break;
                }

                return $targets;
            };

            $targets = $resolveTargets($action, $caster, $players, $monsters);

            try {
                $skillId = (int)($action['skill_id'] ?? 0);
                $battleActions = new \App\Battle\BattleActions();

                foreach ($targets as $t) {
                    $targetId = $t['id'];
                    $targetKey = $t['ref_key'];

                    // Carrega target atual do Redis (garante que passamos o estado atual)
                    $targetJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    $targetRef = $targetJson ? json_decode($targetJson, true) : null;

                    // Executa ação (note que executeAction grava no Redis via saveEntityState)
                    $battleActions->executeAction($caster, $skillId, $targetRef, $battleId, ($caster['type'] ?? 'character'));

                    // NÃO sobrescrever o Redis com a cópia local; em vez disso,
                    // ler o estado atualizado que saveEntityState já gravou e sincronizar memória
                    $freshJson = Redis::hget("battle:$battleId:$targetKey", $targetId);
                    if ($freshJson) {
                        $fresh = json_decode($freshJson, true);
                        if ($targetKey === 'monsters') {
                            $monsters[$targetId] = $fresh;
                        } else {
                            $players[$targetId] = $fresh;
                        }
                    } else {
                        // Caso incomum: se não existir no Redis, logamos para investigar
                        Log::warning("After applySkill, fresh entity missing in Redis", [
                            'battleId' => $battleId,
                            'targetKey' => $targetKey,
                            'targetId' => $targetId
                        ]);
                    }
                }

                $processed = true;
            } catch (\Exception $e) {
                Log::error("Error processing action for player $characterId: " . $e->getMessage(), ['exception' => $e]);
            }

            // remove a ação processada
            Redis::hdel($pendingActionsKey, $characterId);
        }

        return $processed;
    }


    // =========================
    // Processa comportamento dos monstros
    // =========================
    public function processBattleMonsters(string $battleId): bool
    {
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");
        $playersRaw = Redis::hgetall("battle:$battleId:characters_data");

        if (!$monstersRaw || !$playersRaw) {
            Log::warning("[processBattleMonsters] Sem monstros ou jogadores na batalha $battleId");
            return false;
        }

        $monsters = [];
        foreach ($monstersRaw as $k => $json) $monsters[$k] = json_decode($json, true);

        $players = [];
        foreach ($playersRaw as $k => $json) $players[$k] = json_decode($json, true);

        $processed = false;

        foreach ($monsters as $monsterKey => &$monster) {
            $currentStamina = StaminaService::getCurrentStamina($battleId, (string)$monsterKey, 'monster');
            $monster['current_stamina'] = $currentStamina;

            Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) current stamina: $currentStamina");

            $behavior = $this->resolveBehavior($monster['type'] ?? '');
            $targetPlayer = reset($players);
            $targetPlayerKey = key($players);

            $action = $behavior->decideAction($monster, [
                'monsters' => $monsters,
                'players' => $players,
                'battle_id' => $battleId,
            ]);

            if ($action) {
                $skillId = (int)($action['skill_id'] ?? 0);
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) decided to use skill ID: $skillId on player {$targetPlayer['name']} ({$targetPlayerKey})");

                try {
                    BattleBattleActions::executeAction($monster, $skillId, $players[$targetPlayerKey], $battleId, 'monster');
                    $processed = true;
                    Log::info("[processBattleMonsters] Monster action executed and states updated in Redis for monster {$monsterKey} and player {$targetPlayerKey}");
                } catch (\Throwable $e) {
                    Log::error("[processBattleMonsters] Erro ao processar ação do monstro {$monsterKey}: " . $e->getMessage(), ['exception' => $e]);
                }
            } else {
                Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) não realizou nenhuma ação");
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
}
