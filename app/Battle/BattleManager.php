<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use App\Services\Battle\BattleBroadcaster;

class BattleManager
{
    private BattleActions $battleActions;

    public function __construct()
    {
        $this->battleActions = new BattleActions($this);
    }

    public function getBattleActions(): BattleActions
    {
        return $this->battleActions;
    }

    public function createBattle(string $battleId, array $battleData): void
    {
        Log::info("Creating battle $battleId", ['battleData' => $battleData]);

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
        // Remove da lista de batalhas ativas
        Redis::srem('battles:active', $battleId);

        // Função auxiliar para deletar keys usando SCAN
        $deleteKeysByPattern = function (string $pattern) {
            $cursor = '0';
            do {
                [$cursor, $keys] = Redis::scan($cursor, 'MATCH', $pattern, 'COUNT', 100);
                if (!empty($keys)) {
                    Redis::del($keys);
                }
            } while ($cursor != '0');
        };

        // Deleta todas as keys da batalha
        $deleteKeysByPattern("battle:$battleId*");

        // Deleta keys de cooldowns globais associados à batalha
        $deleteKeysByPattern("global_cooldown_at:$battleId*");

        Log::info("Battle $battleId finalized and all associated data cleaned.");
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

    private function getEntities(string $battleId, string $key): array
    {
        $raw = Redis::hgetall("battle:$battleId:$key");
        $entities = [];
        foreach ($raw as $id => $json) {
            $entities[$id] = json_decode($json, true);
        }
        return $entities;
    }

    public function processBattleUsers(string $battleId): bool
    {
        $players = $this->getEntities($battleId, 'characters_data');
        $monsters = $this->getEntities($battleId, 'monsters');
        $pendingActionsKey = "battle:$battleId:pending_actions";
        $pendingActions = Redis::hgetall($pendingActionsKey);

        if (!$players || !$pendingActions) return false;

        $processed = false;

        foreach ($pendingActions as $playerId => $actionJson) {
            $action = json_decode($actionJson, true);
            if (!isset($players[$playerId])) {
                Redis::hdel($pendingActionsKey, $playerId);
                continue;
            }

            $caster = &$players[$playerId];
            $targets = $this->resolveTargets($action, $caster, $players, $monsters);

            if (empty($targets)) {
                Redis::hdel($pendingActionsKey, $playerId);
                continue;
            }

            try {
                $skillId = (int)($action['skill_id'] ?? 0);
                $this->battleActions->executeAction($caster, $skillId, $targets, $battleId, $caster['type'] ?? 'character');
                $processed = true;
            } catch (\Throwable $e) {
                Log::error("[BattleManager] Error processing player action $playerId: " . $e->getMessage(), ['exception' => $e]);
            }

            Redis::hdel($pendingActionsKey, $playerId);
        }

        return $processed;
    }

    public function processBattleMonsters(string $battleId): bool
    {
        $monsters = $this->getEntities($battleId, 'monsters');
        $players = $this->getEntities($battleId, 'characters_data');

        if (!$monsters || !$players) return false;

        $processed = false;

        foreach ($monsters as $monsterKey => &$monster) {
            $monster['current_stamina'] = StaminaService::getCurrentStamina($battleId, (string)$monsterKey, 'monster');

            Log::info("[BattleManager] Processando monstro " . $monsterKey . " (" . ($monster['name'] ?? 'Unknown') . "), stamina: " . $monster['current_stamina']);

            $behavior = $this->resolveBehavior($monster['type'] ?? '');
            if (!$behavior) {
                Log::warning("[BattleManager] Nenhum comportamento definido para tipo '{$monster['type']}'");
                continue;
            }

            $action = $behavior->decideAction($monster, [
                'monsters' => $monsters,
                'players' => $players,
                'battle_id' => $battleId,
            ]);

            if (!$action) {
                Log::info("[BattleManager] Monstro {$monster['name']} decidiu não agir (stamina baixa ou nenhuma ação disponível)");
                continue;
            }

            Log::info(
                "[BattleManager] Monstro " . ($monster['name'] ?? 'Unknown') .
                    " escolheu skill " . $action['skill_id'] .
                    " com target_type " . $action['target_type'] .
                    " e target_id " . ($action['target_id'] ?? 'null')
            );


            $targets = $this->resolveTargets($action, $monster, $players, $monsters);
            if (empty($targets)) {
                Log::warning("[BattleManager] Nenhum alvo resolvido para ação do monstro {$monster['name']}, skill {$action['skill_id']}");
                continue;
            }

            foreach ($targets as $t) {
                Log::info(
                    "[BattleManager] Target resolvido: " .
                        ($t['name'] ?? ($t['username'] ?? 'Desconhecido')) .
                        " (HP: " . ($t['hp'] ?? 0) . ")"
                );
            }

            try {
                $skillId = (int)($action['skill_id'] ?? 0);
                $this->battleActions->executeAction($monster, $skillId, $targets, $battleId, 'monster');
                $processed = true;
                Log::info("[BattleManager] Monstro {$monster['name']} executou skill {$skillId}");
            } catch (\Throwable $e) {
                Log::error("[BattleManager] Erro ao processar monstro $monsterKey: " . $e->getMessage(), ['exception' => $e]);
            }
        }

        return $processed;
    }

    public function checkBattleOutcome(string $battleId): void
    {
        $charactersAlive = array_filter($this->getEntities($battleId, 'characters_data'), fn($c) => ($c['hp'] ?? 0) > 0);
        $monstersAlive = array_filter($this->getEntities($battleId, 'monsters'), fn($m) => ($m['hp'] ?? 0) > 0);

        $messages = [];

        if (empty($charactersAlive)) {
            $messages[] = "Todos os jogadores foram derrotados!";
        } elseif (empty($monstersAlive)) {
            $messages[] = "Todos os monstros foram derrotados! Vitória!";
        }

        if (!empty($messages)) {
            BattleBroadcaster::broadcastToBattle($battleId, ['general' => ['globalMessages' => $messages]], 'battleEnded');
            $this->finishBattle($battleId);
        }
    }

    protected function resolveTargets(array $action, array $caster, array $players, array $monsters): array
    {
        $targets = [];
        Log::info("[resolveTargets] \$action " . json_encode($action));

        $casterType = $caster['type'] ?? 'character'; // 'character' ou 'monster'

        switch ($action['target_type'] ?? '') {
            case 'self':
                $targets[] = $caster;
                break;

            case 'enemy':
                $targetId = $action['target_id'] ?? null;
                $enemies = $casterType === 'monster' ? $players : $monsters;

                foreach ($enemies as $e) {
                    if (($e['instanceId'] ?? null) == $targetId) {
                        $targets[] = $e;
                        break;
                    }
                }
                break;

            case 'ally':
                $targetId = $action['target_id'] ?? null;
                $allies = $casterType === 'monster' ? $monsters : $players;

                foreach ($allies as $a) {
                    if (($a['instanceId'] ?? null) == $targetId) {
                        $targets[] = $a;
                        break;
                    }
                }
                // Permite escolher self também
                if (($caster['instanceId'] ?? null) == $targetId) {
                    $targets[] = $caster;
                }
                break;

            case 'all_enemies':
                $enemies = $casterType === 'monster' ? $players : $monsters;
                foreach ($enemies as $e) {
                    $targets[] = $e;
                }
                break;

            case 'all_allies':
                $allies = $casterType === 'monster' ? $monsters : $players;
                foreach ($allies as $a) {
                    $targets[] = $a; // inclui self também
                }
                break;

            default:
                Log::warning("[BattleManager] Tipo de alvo desconhecido: {$action['target_type']}");
                break;
        }

        foreach ($targets as $t) {
            Log::info(
                "[BattleManager] Target resolvido: " .
                    ($t['name'] ?? 'Desconhecido') .
                    " (HP: " . ($t['hp'] ?? 0) . ")"
            );
        }

        return $targets;
    }






    protected function resolveBehavior(string $type)
    {
        $map = [
            'goblin' => \App\Battle\MonstersBehavior\GoblinBehavior::class,
            'orc'    => \App\Battle\MonstersBehavior\OrcBehavior::class,
        ];

        if (!isset($map[$type])) {
            return null;
        }

        return app($map[$type]);
    }
}
