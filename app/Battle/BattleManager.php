<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use App\Services\Battle\BattleBroadcaster;

class BattleManager
{
    public function createBattle(string $battleId, array $battleData): void
    {
        Log::info("Creating battle $battleId with data:", ['battleData' => $battleData]);

        foreach ($battleData['monsters'] as $index => $monster) {
            Redis::hset("battle:$battleId:monsters", $index, json_encode($monster));
            Log::debug("Saved monster at index $index for battle $battleId");
        }

        foreach ($battleData['characters'] as $charId => $character) {
            Redis::hset("battle:$battleId:players", $charId, json_encode($character));
            Log::debug("Saved character $charId for battle $battleId");
        }

        Redis::sadd('battles:active', $battleId);
        $this->updateLastUpdate($battleId);

        Log::info("Battle $battleId created and added to active battles.");
    }

    public function finishBattle(string $battleId): void
    {
        Log::info("Finalizing battle $battleId, cleaning up data.");
        Redis::srem('battles:active', $battleId);

        $keys = [
            "battle:$battleId:users",
            "battle:$battleId:players",
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

        Log::info("Cleaning up old battles, found active battles:", ['count' => count($battleIds), 'battles' => $battleIds]);

        foreach ($battleIds as $battleId) {
            $lastUpdate = Redis::get("battle:$battleId:last_update");
            Log::debug("Battle $battleId last update timestamp: $lastUpdate");

            if (!$lastUpdate || ($now - (int)$lastUpdate) > $maxAgeSeconds) {
                Log::info("Cleaning up old battle $battleId due to inactivity.");
                $this->finishBattle($battleId);
            }
        }
    }

    public function updateLastUpdate(string $battleId): void
    {
        $time = time();
        Redis::set("battle:$battleId:last_update", $time);
        Log::debug("Updated last_update for battle $battleId to $time");
    }

    public function processBattles(): void
    {
        $battleIds = Redis::smembers('battles:active');
        //Log::info("Processing battles, active battle count: " . count($battleIds));

        foreach ($battleIds as $battleId) {
            Log::info("Processing battle $battleId");

            $monstersRaw = Redis::hgetall("battle:$battleId:monsters");
            if (!$monstersRaw) {
                Redis::srem('battles:active', $battleId);
                Log::warning("Removed invalid battle $battleId (no monsters).");
                continue;
            }
            Log::debug("Loaded monsters for battle $battleId", ['monsters' => $monstersRaw]);

            $playersRaw = Redis::hgetall("battle:$battleId:characters");
            if (!$playersRaw) {
                Log::warning("Battle $battleId has no players.");
                continue;
            }
            Log::debug("Loaded players for battle $battleId", ['characters' => $playersRaw]);

            $monsters = [];
            foreach ($monstersRaw as $key => $json) {
                $monsters[$key] = json_decode($json, true);
            }

            $players = [];
            foreach ($playersRaw as $key => $json) {
                $players[$key] = json_decode($json, true);
            }

            foreach ($monsters as $monsterKey => &$monster) {
                // Pega stamina atual usando StaminaService
                $currentStamina = StaminaService::getCurrentStamina($battleId, (string)$monsterKey, 'monster');

                // Atualiza o array do monstro para passar para a decisão de comportamento
                $monster['current_stamina'] = $currentStamina;

                // Aqui pode ter uma estratégia para comportamento do monstro
                $behavior = $this->resolveBehavior($monster['type'] ?? '');

                // Seleciona alvo (exemplo: primeiro jogador)
                $targetPlayer = reset($players);
                $targetPlayerKey = key($players);

                Log::info("Monster {$monster['name']} deciding action with stamina $currentStamina against player {$targetPlayer['name']}.");

                $action = $behavior->decideAction($monster, [
                    'monsters' => $monsters,
                    'players' => $players,
                    'battle_id' => $battleId,
                ]);

                Log::info("Monster {$monster['name']} decided action: " . ($action ?? 'none'));

                if ($action) {
                    BattleActions::executeAction($monster, $action, $players[$targetPlayerKey], $battleId, 'monster');
                }
            }

            // Atualiza estado no Redis
            foreach ($monsters as $key => $monster) {
                Redis::hset("battle:$battleId:monsters", $key, json_encode($monster));
                Log::debug("Saved updated monster $key for battle $battleId");
            }

            foreach ($players as $key => $player) {
                Redis::hset("battle:$battleId:players", $key, json_encode($player));
                Log::debug("Saved updated player $key for battle $battleId");
            }

            $this->updateLastUpdate($battleId);
        }
    }

    protected function resolveBehavior(string $type)
    {
        $map = [
            'goblin' => \App\Battle\MonstersBehavior\GoblinBehavior::class,
            'orc' => \App\Battle\MonstersBehavior\OrcBehavior::class,
        ];

        if (isset($map[$type])) {
            Log::debug("Resolved behavior for monster type $type");
            return app($map[$type]);
        }

        Log::debug("Resolved default behavior for monster type $type (unknown)");

        return new class implements \App\Battle\MonstersBehavior\MonsterBehaviorInterface {
            public function decideAction(array $monsterData, array $battleState): ?string
            {
                return null;
            }
        };
    }
}
