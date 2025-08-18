<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Soul;
use App\Models\SoulGrid;
use App\Models\Character;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Models\SoulGridInventory;   // novo
use App\Models\SoulInventory;       // novo
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class ConnectToServerHandler implements HandlesUnityEvent
{

    private static array $defaultSkills = [
        0 => [
            'name' => 'Attack',
            'type' => 'physical',
            'power' => 0,
            'stamina_cost' => 20,
            'pre_delay' => 0,
            'post_delay' => 500,
            'level' => 1,
        ],
        1 => [
            'name' => 'Fire Ball',
            'type' => 'magical',
            'power' => 25,
            'stamina_cost' => 11,
            'pre_delay' => 500,
            'post_delay' => 1000,
            'level' => 1,
        ],
        2 => [
            'name' => 'Raise Defense',
            'type' => 'buff',
            'stat' => 'physical_defense_bonus',
            'bonus' => 5,
            'duration' => 3,
            'stamina_cost' => 10,
            'pre_delay' => 300,
            'post_delay' => 500,
            'level' => 1,
        ],
        3 => [
            'name' => 'Heal',
            'type' => 'heal',
            'power' => 20,
            'stamina_cost' => 8,
            'pre_delay' => 400,
            'post_delay' => 700,
            'level' => 1,
        ],
        4 => [
            'name' => 'Wait',
            'type' => 'buff',
            'stat' => 'physical_defense_bonus',
            'bonus' => 0,
            'duration' => 3,
            'stamina_cost' => 0,
            'pre_delay' => 300,
            'post_delay' => 500,
            'level' => 1,
        ],
        5 => [
            'name' => 'Death',
            'type' => 'debuff',
            'stat' => 'death',
            'bonus' => 0,
            'power' => 20,
            'stamina_cost' => 10,
            'pre_delay' => 300,
            'post_delay' => 500,
            'level' => 1,
        ]
    ];

    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            $connection->send(json_encode([
                'event'   => 'character_invalid',
                'message' => 'Missing data payload.',
            ]));
            return;
        }

        $characterId = $data['character_id'] ?? null;

        if ($characterId) {
            $character = Character::where('id', $characterId)
                ->where('user_id', $userId)
                ->first();

            if (!$character) {
                $connection->send(json_encode([
                    'event'   => 'character_invalid',
                    'message' => 'Character not found or does not belong to user.',
                ]));
                return;
            }
        } else {
            // Se não houver character_id, tentamos criar ou pegar o primeiro do usuário
            $character = $this->createCharacter($userId);
        }

        // garante relação carregada
        $character->load('stats');

        // ===== preparar stats =====
        $statsArray = $character->stats ? $character->stats->toArray() : [];
        $statsJson  = json_encode($statsArray, JSON_UNESCAPED_UNICODE);

        if ($statsJson === false) {
            Log::error('Falha ao serializar stats', ['character_id' => $character->id, 'err' => json_last_error_msg()]);
            $statsJson  = json_encode([]);
            $statsArray = [];
        }

        // ===== salvar no Redis (stats como string JSON) =====
        Redis::hset("session:$token", 'character_id', (string) $character->id);
        Redis::hmset("character_session:{$character->id}", [
            'id'         => (string) $character->id,
            'user_id'    => (string) $userId,
            'name'       => (string) $character->name,
            'created_at' => $character->created_at->toDateTimeString(),
            'updated_at' => $character->updated_at->toDateTimeString(),
            'stats'      => $statsJson, // importantíssimo: JSON string no Redis
        ]);

        // ===== montar payload para Unity =====
        $payloadToSend = [
            'event' => 'subscribeMe',
            'data'  => [
                'channel' => "character.{$character->id}",
                'character' => [
                    'id'    => $character->id,
                    'name'  => $character->name,
                    'stats' => $statsArray, // ENSINA: enviar como array/objeto aqui
                ],
            ],
        ];

        // debug útil: loga o payload *antes* do json_encode final para confirmar tipo
        Log::debug('WS OUT (subscribeMe)', $payloadToSend);

        $connection->send(json_encode($payloadToSend, JSON_UNESCAPED_UNICODE));
    }

    private function createDefaultSkills(): void
    {
        foreach (self::$defaultSkills as $id => $skillData) {
            \App\Models\Skill::firstOrCreate(
                ['id' => $id],
                $skillData
            );
        }
    }
    /**
     * Cria um personagem com Stats, SoulInventory e SoulGridInventory
     */
    private function createCharacter(int $userId): Character
    {
        Log::info("NOVO PERSONAGEM CRIADO");

        // === 1. Cria o character ===
        $character = Character::create([
            'user_id' => $userId,
            'name'    => "Hero_{$userId}",
        ]);

        // === 2. Cria stats iniciais ===
        $character->stats()->create([
            'hp'             => 100,
            'level'          => 1,
            'strength'       => 10,
            'intelligence'   => 5,
            'defense_bonus'  => 8,
            'mdefense_bonus' => 8,
            'dexterity'      => 7,
            'stamina'        => 12,
        ]);

        // === 3. Cria inventários vazios ===
        $character->soulInventory()->create();      // inventário vazio de Souls
        $character->soulGridInventory()->create();  // inventário vazio de SoulGrids

        // === 4. Cria Skills iniciais, se não existirem ===
        $this->createDefaultSkills();

        // === 5. Cria e equipa uma réplica da SoulGrid inicial ===
        $templateGrid = SoulGrid::where('name', 'Starter Grid')->first();

        if (!$templateGrid) {
            $templateGrid = SoulGrid::create([
                'name'        => 'Starter Grid',
                'slots_count' => 4,
            ]);

            // Cria stats básicos para o template
            $templateGrid->stats()->create([
                'hp'             => 50,
                'strength'       => 5,
                'intelligence'   => 3,
                'defense_bonus'  => 2,
                'mdefense_bonus' => 2,
                'dexterity'      => 2,
                'stamina'        => 5,
            ]);
        }

        // Replica e equipa ao character
        $equippedGrid = $templateGrid->replicateForCharacter($character);

        // === 6. Cria algumas Souls iniciais e associa ao grid equipado ===
        $initialSoulsData = [
            ['name' => 'Soul A'],
            ['name' => 'Soul B'],
        ];

        foreach ($initialSoulsData as $soulData) {
            $soul = Soul::create($soulData);

            // Associa Skills à Soul
            $skillIds = [0, 1, 2, 5]; // Skills iniciais
            $soul->skills()->sync($skillIds);

            // Equipa a Soul na SoulGrid replicada
            $equippedGrid->souls()->attach($soul->id);
        }

        return $character;
    }


    /**
     * Normaliza um valor de "stats" que você pode ter vindo do Redis/Outro handler:
     * - se for string -> tenta json_decode
     * - se for "Array" (literal) -> retorna []
     * - se for array/object -> retorna array
     */
    protected function normalizeStatsValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (is_string($value)) {
            // strings "Array" aparecem quando alguém gravou diretamente um array no hset
            if ($value === 'Array') {
                return [];
            }
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            // fallback
            return [];
        }

        return [];
    }

    /**
     * Exemplo de leitura do Redis usando normalização (use quando montar payloads a partir do Redis)
     */
    protected function buildCharacterPayloadFromRedis(int $characterId): array
    {
        $hash = Redis::hgetall("character_session:{$characterId}");
        if (empty($hash)) {
            return [];
        }
        $stats = $this->normalizeStatsValue($hash['stats'] ?? null);

        return [
            'id'   => isset($hash['id']) ? (int)$hash['id'] : $characterId,
            'name' => $hash['name'] ?? null,
            'stats' => $stats,
        ];
    }
}
