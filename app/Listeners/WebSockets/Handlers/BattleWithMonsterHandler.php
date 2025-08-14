<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use App\Services\Battle\BattleBroadcaster;
use Laravel\Reverb\Contracts\Connection;

class BattleWithMonsterHandler
{
    protected StaminaService $staminaService;

    public function __construct(StaminaService $staminaService)
    {
        $this->staminaService = $staminaService;
    }

    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        // 1. Busca os dados da sessão pelo token
        $sessionData = Redis::hgetall("session:$token");
        $characterId = $sessionData['character_id'] ?? null;

        if (!$characterId) {
            $connection->send(json_encode(['error' => 'Character ID not found in session']));
            Log::warning("No character_id found in session for user $userId and token $token");
            return;
        }

        // 2. Busca os dados do personagem
        $characterJson = Redis::hgetall("character_session:$characterId");

        if (!$characterJson || empty($characterJson)) {
            $connection->send(json_encode(['error' => 'Character data not found']));
            Log::warning("Character data missing for character_id $characterId");
            return;
        }

        Log::info("CharacterJson = " . json_encode($characterJson));

        // 3. Criar ID único para a batalha
        $battleId = uniqid('battle_', true);

        // 4. Registrar personagem na batalha
        Redis::sadd("battle:$battleId:characters", $characterId);
        Redis::hset("battle:$battleId:characters_data", $characterId, json_encode($characterJson));

        // 5. Vincular battle_instance_id na sessão
        Redis::hset("session:$token", 'battle_instance_id', $battleId);

        // 6. Criar monstro
        $monster = [
            'monster_id' => 123,
            'name' => "Goblin",
            'maxhp' => 80,
            'hp' => 80,
            'level' => 1,
            'pattack' => 12,
            'mattack' => 6,
            'defense' => 5,
            'stamina' => 25,
            'agility' => 80,
            'type' => 'goblin',
        ];

        $monsterInstanceId = 1;
        $monster['instanceId'] = (string) $monsterInstanceId;
        Redis::hset("battle:$battleId:monsters", (string)$monsterInstanceId, json_encode($monster));

        $now = now()->timestamp;

        // 7. Inicializar stamina
        $monsterStaminaData = $this->staminaService->initializeStamina(
            $now,
            $monster['stamina'],
            $monster['agility']
        );
        Redis::hset("battle:$battleId:stamina_data", "monster:{$monsterInstanceId}", json_encode($monsterStaminaData));

        $characterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int) ($characterJson['stamina'] ?? 0),
            (int) ($characterJson['agility'] ?? 0)
        );
        Redis::hset("battle:$battleId:stamina_data", "character:$characterId", json_encode($characterStaminaData));


        /*$connection->send(json_encode([
            'event' => 'unity-response',
            'character' => [
                $characterId => $characterJson
            ],
            'data' => [
                'message' => 'Battle created with Goblin!',
                'battle_instance' => $battleId,
                'monsters' => [$monster]
            ]
        ]));*/

        // 8. Broadcast inicial para o canal do personagem
        BattleBroadcaster::broadcastToCharacter(
            $characterId,
            [
                //'message' => 'Battle created with Goblin!',
                //'battle_instance' => $battleId,
                //'monsters' => [$monster],
                'player' => [
                    'instanceId' => (string)$characterId,
                    'hp' => (int) $characterJson['hp'],
                    'staminaData' => $characterStaminaData
                ],
                'general' => ['globalMessages' => ["Battle's started!"]]
            ],
            'updateYourself'
        );

        Redis::sadd('battles:active', $battleId);
        Log::info("Battle $battleId created and sent to character.$characterId for user $userId.");
    }
}
