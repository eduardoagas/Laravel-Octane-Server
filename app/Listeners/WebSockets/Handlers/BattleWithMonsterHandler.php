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

        // 2. Busca os dados do personagem pela key específica
        $characterJson = Redis::hgetall("character_session:$characterId");

        if (!$characterJson || empty($characterJson)) {
            $connection->send(json_encode(['error' => 'Character data not found']));
            Log::warning("Character data missing for character_id $characterId");
            return;
        }

        Log::info("CharacterJson = " . json_encode($characterJson));

        // 3. Criar ID único para a batalha
        $battleId = uniqid('battle_', true);

        // 4. Registrar usuário e personagem na batalha
        Redis::sadd("battle:$battleId:users", $userId);
        Redis::hset("battle:$battleId:characters", $characterId, json_encode($characterJson));

        // 5. Vincular battle_instance_id na sessão
        Redis::hset("session:$token", 'battle_instance_id', $battleId);

        // 6. Registra batalha ativa no conjunto global
        Redis::sadd('battles:active', $battleId);

        // 7. Criar monstro
        $goblin = [
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
        $monsterInstanceIdstr = (string)$monsterInstanceId;
        $goblin['instanceId'] = $monsterInstanceIdstr;
        Redis::hset("battle:$battleId:monsters", $monsterInstanceIdstr, json_encode($goblin));

        $now = now()->timestamp;

        // 8. Inicializar stamina para monstro e personagem
        $monsterStaminaData = $this->staminaService->initializeStamina(
            $now,
            $goblin['stamina'],
            $goblin['agility']
        );
        Redis::hset("battle:$battleId:stamina_data", "monster:$monsterInstanceIdstr", json_encode($monsterStaminaData));

        $characterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int) ($characterJson['stamina'] ?? 0),
            (int) ($characterJson['agility'] ?? 0)
        );
        Redis::hset("battle:$battleId:stamina_data", "character:$characterId", json_encode($characterStaminaData));

        // 9. Resposta inicial ao cliente
        $connection->send(json_encode([
            'event' => 'unity-response',
            'character' => [
                $characterId => $characterJson
            ],
            'data' => [
                'message' => 'Battle created with Goblin!',
                'battle_instance' => $battleId,
                'monsters' => [$goblin]
            ]
        ]));

        // 10. Pedido para assinar o canal da batalha
        $connection->send(json_encode([
            'event' => 'subscribeMe',
            'data' => [
                'channel' => "battle.$battleId"
            ]
        ]));

        // 11. Disparar updateYourself inicial
        BattleBroadcaster::broadcastToBattle($battleId, [
            'event' => 'updateYourself',
            'data' => [
                'players' => [
                    [
                        'instanceId' => (string)$characterId,
                        'currentHp' => (int) ($characterJson['hp'] ?? 0),
                        'currentStamina' => (int) ($characterJson['stamina'] ?? 0),
                        'nstatus' => 'none',
                        'pstatus' => 'none'
                    ]
                ],
                'enemies' => [
                    [
                        'instanceId' => $monsterInstanceIdstr,
                        'nstatus' => 'none',
                        'isAlive' => true
                    ]
                ],
                'general' => [
                    'actionInfoUse' => 'Battle start',
                    'actionInfoResult' => '',
                    'globalMessages' => [
                        'Battle created with Goblin!'
                    ]
                ]
            ]
        ]);

        Log::info("Battle $battleId created and updateYourself sent for user $userId.");
    }
}
