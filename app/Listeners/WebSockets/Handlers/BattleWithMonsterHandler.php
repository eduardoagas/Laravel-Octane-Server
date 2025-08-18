<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Monster;
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
        // 1️⃣ Busca os dados da sessão pelo token
        $sessionData = Redis::hgetall("session:$token");
        $characterId = $sessionData['character_id'] ?? null;

        if (!$characterId) {
            $connection->send(json_encode(['error' => 'Character ID not found in session']));
            Log::warning("No character_id found in session for user $userId and token $token");
            return;
        }

        // 2️⃣ Busca os dados do personagem
        $characterJson = Redis::hgetall("character_session:$characterId");
        if (!$characterJson || empty($characterJson)) {
            $connection->send(json_encode(['error' => 'Character data not found']));
            Log::warning("Character data missing for character_id $characterId");
            return;
        }

        // 2.1️⃣ Decodifica os stats e adiciona current_hp dentro de stats
        $stats = isset($characterJson['stats']) ? json_decode($characterJson['stats'], true) : [];
        $stats['current_hp'] = $stats['hp'] ?? 0; // 🔹 Novo: current_hp incluído nos stats
        $characterJson['stats'] = json_encode($stats); // 🔹 Atualiza o JSON para Redis

        // 3️⃣ Criar ID único para a batalha
        $battleId = uniqid('battle_', true);

        // 4️⃣ Definir instanceId incremental para o personagem
        $currentPlayers = Redis::hlen("battle:$battleId:characters_data");
        $playerInstanceId = $currentPlayers + 1;
        $characterJson['instanceId'] = (string)$playerInstanceId;

        // 5️⃣ Registrar personagem na batalha
        Redis::sadd("battle:$battleId:characters", $characterId);
        Redis::hset("battle:$battleId:characters_data", $characterId, json_encode($characterJson));

        // 6️⃣ Vincular battle_instance_id na sessão
        Redis::hset("session:$token", 'battle_instance_id', $battleId);

        // 7️⃣ Criar monstro com instanceId incremental
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");
        $maxMonsterInstance = 0;
        foreach ($monstersRaw as $m) {
            $data = json_decode($m, true);
            if (isset($data['instanceId'])) {
                $maxMonsterInstance = max($maxMonsterInstance, (int)$data['instanceId']);
            }
        }
        $monsterInstanceId = $maxMonsterInstance + 1;

        // 7.1️⃣ Busca ou cria o monstro
        $monster = Monster::where('monster_id', 1)->with('stats')->first();
        if (!$monster) {
            $monster = Monster::create([
                'monster_id' => 1,
                'name'       => 'Goblin',
                'type'       => 'goblin',
            ]);

            $monster->stats()->create([
                'hp'            => 1400,
                'level'         => 1,
                'strength'      => 12,
                'intelligence'  => 6,
                'defense_bonus' => 5,
                'dexterity'     => 1,
                'stamina'       => 25,
            ]);

            $monster->load('stats');
        }

        // 7.2️⃣ Monta payload do monstro com current_hp dentro de stats
        $monsterStats = $monster->stats->toArray();
        $monsterStats['current_hp'] = $monsterStats['hp'] ?? 0; // 🔹 Novo: current_hp incluído
        $monsterPayload = [
            'monster_id' => $monster->monster_id,
            'name'       => $monster->name,
            'type'       => $monster->type,
            'instanceId' => (string)$monsterInstanceId,
            'stats'      => json_encode($monsterStats),
        ];

        // 7.3️⃣ Salva monstro no Redis
        Redis::hset("battle:$battleId:monsters", (string)$monsterInstanceId, json_encode($monsterPayload));

        $now = now()->timestamp;

        // 8️⃣ Inicializar stamina do monstro
        $monsterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int)($monsterStats['stamina'] ?? 0),
            (int)($monsterStats['dexterity'] ?? 0)
        );
        Redis::hset("battle:$battleId:stamina_data", "monster:{$monsterInstanceId}", json_encode($monsterStaminaData));

        // 9️⃣ Inicializar stamina do personagem
        $characterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int)($stats['stamina'] ?? 0),
            (int)($stats['dexterity'] ?? 0)
        );
        Redis::hset("battle:$battleId:stamina_data", "character:$characterId", json_encode($characterStaminaData));

        // 🔟 Enviar update para o jogador
        $connection->send(json_encode([
            'event'   => 'updateYourself',
            'channel' => "character.{$characterId}",
            'data'    => [
                'players' => [
                    [
                        'instanceId'  => (string)$playerInstanceId,
                        'currentHp'   => (int)($stats['hp'] ?? 0),
                        'staminaData' => $characterStaminaData,
                    ]
                ],
                'general' => ['globalMessages' => ["Battle's started!"]],
            ],
        ]));

        // 11️⃣ Adiciona batalha ativa
        Redis::sadd('battles:active', $battleId);
        Log::info("Battle $battleId created and sent to character.$characterId for user $userId.");
    }
}
