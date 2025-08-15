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

            // Função para resolver múltiplos alvos
            $resolveTargets = function (array $action, array $caster, array $players, array $monsters) {
                $targets = [];

                switch ($action['target_type'] ?? '') {
                    case 'self':
                        $targets[] = $caster;
                        break;

                    case 'enemy':
                        if (($caster['type'] ?? 'character') === 'character' && isset($action['target_id'], $monsters[$action['target_id']])) {
                            $targets[] = $monsters[$action['target_id']];
                        } elseif (($caster['type'] ?? 'character') === 'monster' && isset($action['target_id'], $players[$action['target_id']])) {
                            $targets[] = $players[$action['target_id']];
                        }
                        break;

                    case 'ally':
                        if (($caster['type'] ?? 'character') === 'character' && isset($action['target_id'], $players[$action['target_id']])) {
                            $targets[] = $players[$action['target_id']];
                        } elseif (($caster['type'] ?? 'character') === 'monster' && isset($action['target_id'], $monsters[$action['target_id']])) {
                            $targets[] = $monsters[$action['target_id']];
                        }
                        break;

                    case 'enemies': // múltiplos inimigos
                        if (($caster['type'] ?? 'character') === 'character') {
                            foreach ($monsters as $m) $targets[] = $m;
                        } else {
                            foreach ($players as $p) $targets[] = $p;
                        }
                        break;

                    case 'party': // múltiplos aliados incluindo self
                        if (($caster['type'] ?? 'character') === 'character') {
                            foreach ($players as $p) $targets[] = $p;
                        } else {
                            foreach ($monsters as $m) $targets[] = $m;
                        }
                        break;
                }

                return $targets;
            };

            $targets = $resolveTargets($action, $caster, $players, $monsters);

            if (empty($targets)) {
                Redis::hdel($pendingActionsKey, $characterId);
                continue;
            }

            try {
                $skillId = (int)($action['skill_id'] ?? 0);
                $battleActions = new \App\Battle\BattleActions();
                // Chamada única para todos os alvos
                $battleActions->executeAction($caster, $skillId, $targets, $battleId, ($caster['type'] ?? 'character'));
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

            $behavior = $this->resolveBehavior($monster['type'] ?? '');
            if (!$behavior) continue;

            $action = $behavior->decideAction($monster, [
                'monsters' => $monsters,
                'players' => $players,
                'battle_id' => $battleId,
            ]);

            if (!$action) continue;

            $skillId = (int)($action['skill_id'] ?? 0);
            Log::info("[processBattleMonsters] Monster {$monster['name']} ({$monsterKey}) decided to use skill ID: $skillId");

            try {
                // Resolve múltiplos alvos
                $resolveTargets = function (array $action, array $caster, array $players, array $monsters) {
                    $targets = [];

                    switch ($action['target_type'] ?? '') {
                        case 'self':
                            $targets[] = $caster;
                            break;
                        case 'enemy':
                            if (isset($action['target_id'], $players[$action['target_id']])) {
                                $targets[] = $players[$action['target_id']];
                            }
                            break;
                        case 'ally':
                            if (isset($action['target_id'], $monsters[$action['target_id']])) {
                                $targets[] = $monsters[$action['target_id']];
                            }
                            break;
                        case 'enemies':
                            foreach ($players as $p) $targets[] = $p;
                            break;
                        case 'party':
                            foreach ($monsters as $m) $targets[] = $m;
                            break;
                    }

                    return $targets;
                };

                $targets = $resolveTargets($action, $monster, $players, $monsters);

                if (!empty($targets)) {
                    $battleActions = new \App\Battle\BattleActions();
                    $battleActions->executeAction($monster, $skillId, $targets, $battleId, 'monster');
                    $processed = true;
                }
            } catch (\Throwable $e) {
                Log::error("[processBattleMonsters] Erro ao processar ação do monstro {$monsterKey}: " . $e->getMessage(), ['exception' => $e]);
            }
        }

        return $processed;
    }


    // =========================
    // Resolve targets para qualquer caster (player ou monster)
    // =========================
    private function resolveTargets(array $action, array $caster, array $players, array $monsters): array
    {
        $targets = [];
        $casterType = $caster['type'] ?? 'character';

        switch ($action['target_type'] ?? '') {
            case 'self':
                $targets[] = ['ref_key' => $casterType === 'monster' ? 'monsters' : 'characters_data', 'id' => $caster['instanceId']];
                break;

            case 'enemy':
                if ($casterType === 'character') {
                    if (isset($action['target_id'], $monsters[$action['target_id']])) {
                        $targets[] = ['ref_key' => 'monsters', 'id' => $action['target_id']];
                    }
                } else {
                    if (isset($action['target_id'], $players[$action['target_id']])) {
                        $targets[] = ['ref_key' => 'characters_data', 'id' => $action['target_id']];
                    }
                }
                break;

            case 'ally':
                if ($casterType === 'character') {
                    if (isset($action['target_id'], $players[$action['target_id']])) {
                        $targets[] = ['ref_key' => 'characters_data', 'id' => $action['target_id']];
                    }
                } else {
                    if (isset($action['target_id'], $monsters[$action['target_id']])) {
                        $targets[] = ['ref_key' => 'monsters', 'id' => $action['target_id']];
                    }
                }
                break;

            case 'enemies':
                if ($casterType === 'character') {
                    foreach ($monsters as $mId => $m) $targets[] = ['ref_key' => 'monsters', 'id' => $mId];
                } else {
                    foreach ($players as $pId => $p) $targets[] = ['ref_key' => 'characters_data', 'id' => $pId];
                }
                break;

            case 'party':
                if ($casterType === 'character') {
                    foreach ($players as $pId => $p) $targets[] = ['ref_key' => 'characters_data', 'id' => $pId];
                } else {
                    foreach ($monsters as $mId => $m) $targets[] = ['ref_key' => 'monsters', 'id' => $mId];
                }
                break;
        }

        return $targets;
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
