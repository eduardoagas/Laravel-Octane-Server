<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Character;
use App\Models\Consumable;
use App\Models\Skill;
use App\Models\Soul;
use App\Models\SoulGrid;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use Illuminate\Support\Facades\DB;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;
use App\Listeners\WebSockets\Handlers\CharacterSyncHandler;
use App\Listeners\WebSockets\Handlers\BattlePackUpdateHandler;

class ConnectToServerHandler implements HandlesUnityEvent
{
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
            $character = Character::where('user_id', $userId)->first();
            if (!$character) {
                $character = $this->createCharacter($userId);
            }
        }

        $character->load('stats');

        // --- Salva sessão mínima no Redis ---
        Redis::hset("session:$token", 'character_id', (string)$character->id);
        Redis::hmset("character_session:{$character->id}", [
            'id'      => (string)$character->id,
            'user_id' => (string)$userId,
            'name'    => (string)$character->name,
            'stats'   => json_encode($character->stats ? $character->stats->toArray() : [], JSON_UNESCAPED_UNICODE),
        ]);

        // --- Atualiza stats + soul grid via handler ---
        (new CharacterSyncHandler())->handle($character->id, $connection);

        // --- Atualiza battlepack via handler ---
        (new BattlePackUpdateHandler())->handle($character->id, $connection);

        // --- Agora podemos montar o payload 'subscribeMe' para Unity ---
        $characterPayload = [
            'id'    => $character->id,
            'name'  => $character->name,
            //'stats' => $character->stats ? $character->stats->toArray() : [],
        ];

        // Monta payload final
        $payloadToSend = [
            'event' => 'subscribeMe',
            'data'  => [
                'channel' => "character.{$character->id}",
                'character' => $characterPayload,
            ],
        ];

        // Log útil antes de enviar
        Log::debug('WS OUT (subscribeMe)', $payloadToSend);

        // Envia para o cliente Unity
        $connection->send(json_encode($payloadToSend, JSON_UNESCAPED_UNICODE));

        Log::info("Conexão inicial completa: subscribeMe enviado", ['character_id' => $character->id]);
    }

    private function createCharacter(int $userId): Character
    {
        return DB::transaction(function () use ($userId) {
            Log::info("NOVO PERSONAGEM CRIADO");

            $character = Character::create([
                'user_id' => $userId,
                'name'    => "Hero_{$userId}",
            ]);

            // --- Stats iniciais ---
            $character->stats()->create([
                'hp'             => 1000,
                'level'          => 1,
                'strength'       => 10,
                'intelligence'   => 5,
                'phyiscal_defense'  => 8,
                'magical_defense' => 8,
                'dexterity'      => 7,
                'stamina'        => 12,
            ]);

            // --- Inventários ---
            $character->soulInventory()->create();
            $character->soulGridInventory()->create();
            $consumablesInventory = $character->consumablesInventory()->create();
            $battlePack = $character->battlePack()->create(['max_slots' => 4]);

            // --- Helpers ---
            (new CharacterHelpers())->setupConsumables($consumablesInventory, $battlePack, $character);
            (new CharacterHelpers())->setupSkillsAndSouls($character);
            return $character;
        });
    }
}

/**
 * Classe helper modular
 */
class CharacterHelpers
{
    private static array $defaultConsumables = [
        [
            'name'        => 'Health Potion',
            'description' => 'Restores 50 HP',
            'effect_type' => 'heal',
            'effect_value' => 50,
            'quantity'    => 5,
        ],
        [
            'name'        => 'Stamina Tonic',
            'description' => 'Restores 30 Stamina',
            'effect_type' => 'stamina',
            'effect_value' => 30,
            'quantity'    => 3,
        ],
    ];

    private static array $defaultSkills = [
        1 => [
            'id' => 1,
            'name' => 'Attack',
            'type' => 'physical',
            'power' => 0,
            'stamina_cost' => 15,
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 1,
        ],
        2 => [
            'id' => 2,
            'name' => 'Raise Defense',
            'type' => 'buff',
            'stat' => 'physical_defense',
            'bonus' => 5,
            'duration' => 3,
            'stamina_cost' => 10,
            'pre_delay' => 300,
            'post_delay' => 500,
            'level' => 1,
        ],
        3 => [
            'id' => 3,
            'name' => 'Heal',
            'type' => 'heal',
            'power' => 20, // quantidade de HP curada
            'stamina_cost' => 8,
            'pre_delay' => 400,
            'post_delay' => 700,
            'level' => 1,
        ],
        4 => [
            'id' => 4,
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
            'id' => 5,
            'name' => 'Death',
            'type' => 'debuff',
            'stat' => 'death',
            'bonus' => 0,
            'power' => 20,
            'stamina_cost' => 10,
            'pre_delay' => 300,
            'post_delay' => 500,
            'level' => 1,
        ],
        6 => [
            'id' => 6,
            'name' => 'PoisonTick',
            'type' => 'percentageDamage',
            'stat' => 'poison',
            'power' => 10,
            'duration' => 1,
            'stamina_cost' => 0,
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 1,
            'tick_skill_flag' => true,
        ],
        7 => [
            'id' => 7,
            'name' => 'Poison',
            'type' => 'debuff',
            'power' => 0,
            'duration' => null,
            'stamina_cost' => 15,
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 3,
            'tick_skill_id' => 6,
            'tick_interval' => 5,
        ]
    ];

    public function setupConsumables($inventory, $battlePack, Character $character)
    {
        $equippedSlots = [
            'Health Potion' => 0,
            'Stamina Tonic' => 1,
        ];

        $characterId = $character->id;
        $consumablesKey = "battle:{$characterId}:character:{$characterId}:consumables";

        $consumablesForRedis = [];

        foreach (self::$defaultConsumables as $data) {
            $consumable = Consumable::firstOrCreate(
                ['name' => $data['name']],
                [
                    'description' => $data['description'],
                    'effect_type' => $data['effect_type'],
                    'effect_value' => $data['effect_value'],
                ]
            );

            $item = $inventory->items()->create([
                'consumable_id' => $consumable->id,
                'quantity'      => $data['quantity'],
            ]);

            $slotIndex = $equippedSlots[$data['name']] ?? null;

            if (isset($equippedSlots[$data['name']])) {
                $battlePack->slots()->create([
                    'consumable_item_id' => $item->id,
                    'slot_index'         => $equippedSlots[$data['name']],
                ]);
            }


            $consumablesForRedis[$item->id] = [
                'id'          => $item->id,
                'name'        => $consumable->name,
                'description' => $consumable->description,
                'effect_type' => $consumable->effect_type,
                'effect_value' => $consumable->effect_value,
                'quantity'    => $item->quantity,
                'slot_index'  => $slotIndex,
            ];
        }

        // --- Salva no Redis como hash (mais prático pra atualizar quantities) ---
        Redis::hset($consumablesKey, ...collect($consumablesForRedis)->map(function ($c) {
            return [$c['id'], json_encode($c, JSON_UNESCAPED_UNICODE)];
        })->flatten()->toArray());
    }

    public function setupSkillsAndSouls(Character $character)
    {
        $skills = collect(self::$defaultSkills);

        // primeiro os que não dependem de outro
        foreach ($skills->whereNull('tick_skill_id') as $id => $skillData) {
            $skillData['id'] = $id; // força o ID
            Skill::firstOrCreate(['id' => $id], $skillData);
        }

        // depois os que dependem
        foreach ($skills->whereNotNull('tick_skill_id') as $id => $skillData) {
            $skillData['id'] = $id; // força o ID
            Skill::firstOrCreate(['id' => $id], $skillData);
        }

        $templateGrid = SoulGrid::where('name', 'Starter Grid')->first();
        if (!$templateGrid) {
            $templateGrid = SoulGrid::create(['name' => 'Starter Grid', 'slots_count' => 4]);
            $templateGrid->stats()->create([
                'hp'             => 50,
                'strength'       => 5,
                'intelligence'   => 3,
                'physical_defense'  => 2,
                'magical_defense' => 2,
                'dexterity'      => 2,
                'stamina'        => 5,
            ]);
        }

        $equippedGrid = $templateGrid->replicateForCharacter($character);

        $initialSoulsData = [
            ['name' => 'Soul A', 'skills' => [1, 2, 3, 7]],
            ['name' => 'Soul B', 'skills' => [1, 2, 3, 5]],
        ];

        $tickSkillsForRedis = [];
        $soulsForRedis = [];

        foreach ($initialSoulsData as $soulData) {
            $soul = Soul::create(['name' => $soulData['name']]);
            $soul->skills()->sync($soulData['skills']);
            $equippedGrid->souls()->attach($soul->id);

            $skillsArray = $soul->skills()->get()->map(fn($skill) => [
                'id' => $skill->id,
                'name' => $skill->name,
                'type' => $skill->type,
                'power' => $skill->power ?? 0,
                'stamina_cost' => $skill->stamina_cost ?? 0,
                'pre_delay' => $skill->pre_delay ?? 0,
                'post_delay' => $skill->post_delay ?? 0,
                'duration' => $skill->duration ?? null,
                'level' => $skill->level ?? 1,
                'stat' => $skill->stat ?? null,
                'tick_interval' => $skill->tick_interval ?? null,
                'tick_skill_id' => $skill->tick_skill_id ?? null,
                'tick_skill_flag' => $skill->tick_skill_flag ?? false,
            ])->toArray();

            foreach ($skillsArray as $skill) {
                if (!empty($skill['tick_skill_flag']) || !empty($skill['tick_skill_id'])) {
                    $tickId = $skill['tick_skill_flag'] ? $skill['id'] : $skill['tick_skill_id'];
                    $tickSkillsForRedis[$tickId] = $skill;
                }
            }

            $soulsForRedis[] = [
                'id' => $soul->id,
                'name' => $soul->name,
                'skills' => $skillsArray,
            ];
        }

        // --- Salva no Redis ---
        $characterId = $character->id;
        $gridKey = "battle:{$characterId}:character:{$characterId}:equipped_soul_grid";
        Redis::set($gridKey, json_encode($soulsForRedis, JSON_UNESCAPED_UNICODE));

        $tickSkillsKey = "battle:{$characterId}:character:{$characterId}:tick_skills";
        Redis::set($tickSkillsKey, json_encode(array_values($tickSkillsForRedis), JSON_UNESCAPED_UNICODE));
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
