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

        // 3. Criar ID único para a batalha
        $battleId = uniqid('battle_', true);

        // 4. Definir instanceId incremental para o personagem (prefixando com 'char_')
        $currentPlayers = Redis::hlen("battle:$battleId:characters_data");
        $playerInstanceId = $currentPlayers + 1;
        $characterJson['instanceId'] = 'char_' . $playerInstanceId;

        // 5. Registrar personagem na batalha
        Redis::sadd("battle:$battleId:characters", $characterId);
        Redis::hset("battle:$battleId:characters_data", $characterJson['instanceId'], json_encode($characterJson));

        // 6. Vincular battle_instance_id na sessão
        Redis::hset("session:$token", 'battle_instance_id', $battleId);

        // 7. Criar monstro com instanceId incremental (prefixando com 'mon_')
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");
        $maxMonsterInstance = 0;
        foreach ($monstersRaw as $m) {
            $data = json_decode($m, true);
            if (isset($data['instanceId'])) {
                $numId = (int)preg_replace('/\D/', '', $data['instanceId']);
                $maxMonsterInstance = max($maxMonsterInstance, $numId);
            }
        }
        $monsterInstanceId = $maxMonsterInstance + 1;

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
            'instanceId' => 'mon_' . $monsterInstanceId,
        ];

        Redis::hset("battle:$battleId:monsters", $monster['instanceId'], json_encode($monster));

        $now = now()->timestamp;

        // 8. Inicializar stamina do monstro
        $monsterStaminaData = $this->staminaService->initializeStamina(
            $now,
            $monster['stamina'],
            $monster['agility']
        );
        Redis::hset(
            "battle:$battleId:stamina_data",
            "monster:{$monster['instanceId']}",
            json_encode($monsterStaminaData)
        );

        // Inicializar stamina do personagem
        $characterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int)($characterJson['stamina'] ?? 0),
            (int)($characterJson['agility'] ?? 0)
        );
        Redis::hset(
            "battle:$battleId:stamina_data",
            "character:{$characterJson['instanceId']}",
            json_encode($characterStaminaData)
        );

        // 9. Enviar update para o jogador
        $connection->send(json_encode([
            'event' => 'updateYourself',
            'channel' => "character.{$characterId}",
            'data' => [
                'players' => [
                    [
                        'instanceId' => (string) $characterJson['instanceId'],
                        'currentHp' => (int)$characterJson['hp'],
                        'staminaData' => $characterStaminaData,
                    ]
                ],
                'general' => ['globalMessages' => ["Battle's started!"]]
            ],
        ]));

        // 10. Adiciona batalha ativa
        Redis::sadd('battles:active', $battleId);
        Log::info("Battle $battleId created and sent to character.{$characterId} for user $userId.");
    }
}
