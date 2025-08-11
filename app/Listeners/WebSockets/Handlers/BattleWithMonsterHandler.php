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
        // Buscar o personagem do usuário na sessão Redis
        $characterJson = collect(Redis::keys("character_session:*"))
            ->map(fn($key) => Redis::hgetall($key))
            ->filter()
            ->firstWhere('user_id', (string) $userId);

        Log::info("CharacterJson = " . json_encode($characterJson));

        if (!$characterJson) {
            $connection->send(json_encode(['error' => 'Character not found in session']));
            return;
        }

        // Criar ID único para a batalha
        $battleId = uniqid('battle_', true);
        $characterId = $characterJson['id'];

        // Registrar usuário e personagem na batalha
        Redis::sadd("battle:$battleId:users", $userId);
        Redis::hset("battle:$battleId:characters", $characterId, json_encode($characterJson));

        // Vincular battle_instance_id na sessão
        Redis::hset("session:$token", 'battle_instance_id', $battleId);

        // Registra batalha ativa no conjunto global
        Redis::sadd('battles:active', $battleId);

        // Criar monstro
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

        Redis::hset("battle:$battleId:monsters", $monsterInstanceIdstr, json_encode($goblin));

        $now = now()->timestamp;

        // Inicializar stamina
        $monsterStaminaData = $this->staminaService->initializeStamina(
            $now,
            $goblin['stamina'],
            $goblin['agility']
        );
        Redis::hset("battle:$battleId:stamina_data", "monster:$monsterInstanceIdstr", json_encode($monsterStaminaData));

        $characterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int) $characterJson['stamina'],
            (int) $characterJson['agility']
        );
        Redis::hset("battle:$battleId:stamina_data", "character:$characterId", json_encode($characterStaminaData));

        // Resposta inicial ao cliente
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

        // Pedido para assinar o canal da batalha
        $connection->send(json_encode([
            'event' => 'subscribeMe',
            'data' => [
                'channel' => "battle.$battleId"
            ]
        ]));

        // 🔹 Disparar updateYourself inicial
        BattleBroadcaster::broadcastToBattle($battleId, [
            'event' => 'updateYourself',
            'data' => [
                'players' => [
                    [
                        'instanceId' => (string)$characterId,
                        'currentHp' => (int)$characterJson['hp'],
                        'currentStamina' => (int)$characterJson['stamina'],
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
