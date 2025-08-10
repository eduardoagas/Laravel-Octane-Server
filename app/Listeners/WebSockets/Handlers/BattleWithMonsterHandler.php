<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
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

        // Criar um goblin (monstro fixo)
        $monsters = [];

        $battleId = uniqid('battle_', true);
        $characterId = $characterJson['id'];

        // Registrar usuário e personagem na batalha
        Redis::sadd("battle:$battleId:users", $userId);
        Redis::hset("battle:$battleId:characters", $characterId, json_encode($characterJson));

        // Vincular battle_instance_id na sessão
        Redis::hset("session:$token", 'battle_instance_id', $battleId);

        // Registra batalha ativa no conjunto global
        Redis::sadd('battles:active', $battleId);

        // Criar o goblin fixo
        $goblin = [
            'monster_id' => 123,  // id do tipo goblin (referência banco)
            'name' => "Goblin",
            'maxhp' => 80,
            'hp' => 80,
            'level' => 1,
            'pattack' => 12,
            'mattack' => 6,
            'defense' => 5,
            'stamina' => 25,
            'agility' => 80, // rand(0, 300),
            'type' => 'goblin', // importante para IA
        ];

        $monsters[] = $goblin;

        $monsterInstanceId = 1; // Id da instância dentro da batalha (chave no Redis)

        $monsterInstanceIdstr = (string)$monsterInstanceId;
        // Salvar goblin no hash da batalha, índice 1
        Redis::hset("battle:$battleId:monsters", $monsterInstanceIdstr, json_encode($goblin));

        $now = now()->timestamp;

        // Inicializar stamina do monstro goblin
        $monsterStaminaData = $this->staminaService->initializeStamina(
            $now,
            $goblin['stamina'],
            $goblin['agility']
        );

        Redis::hset("battle:$battleId:stamina_data", "monster:$monsterInstanceIdstr", json_encode($monsterStaminaData));

        // Inicializar stamina do personagem
        $characterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int) $characterJson['stamina'],
            (int) $characterJson['agility']
        );

        Redis::hset("battle:$battleId:stamina_data", "character:$characterId", json_encode($characterStaminaData));


        // Enviar resposta para o cliente com dados da batalha criada
        $connection->send(json_encode([
            'event' => 'unity-response',
            'character' => [
                $characterId => $characterJson
            ],
            'data' => [
                'message' => 'Battle created with Goblin!',
                'battle_instance' => $battleId,
                'monsters' => $monsters
            ]
        ]));

        Log::info("Battle $battleId created with Goblin for user $userId.");

        // Enviar mensagem para o cliente pedindo para se inscrever no canal da batalha
        $connection->send(json_encode([
            'event' => 'subscribeMe',
            'data' => [
                'channel' => "battle.$battleId"
            ]
        ]));
    }
}
