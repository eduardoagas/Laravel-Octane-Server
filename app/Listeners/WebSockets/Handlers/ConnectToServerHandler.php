<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Soul;
use App\Models\SoulGrid;
use App\Models\Character;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use Illuminate\Support\Facades\DB;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class ConnectToServerHandler implements HandlesUnityEvent
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
        0 => [
            'name' => 'Attack',
            'type' => 'physical',
            'power' => 0,
            'stamina_cost' => 10,
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
            'stat' => 'physical_defense',
            'power' => 2,
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
            'duration' => 5, //necessary as it is
            'power' => 20,
            'stamina_cost' => 10,
            'pre_delay' => 300,
            'post_delay' => 500,
            'level' => 1,
        ],
        6 => [ // ✅ PoisonTick
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
        7 => [ // ✅ Poison skill inicial
            'name' => 'Poison',
            'type' => 'debuff',
            'power' => 0,
            'duration' => null, //permanente
            'stamina_cost' => 12,
            'pre_delay' => 300,
            'post_delay' => 500,
            'level' => 3,
            'tick_skill_id' => 6, // referencia para o tick
            'tick_interval' => 5,
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
            // Se não houver character_id, pega o primeiro existente OU cria
            $character = Character::where('user_id', $userId)->first();

            if (!$character) {
                $character = $this->createCharacter($userId);
            }
        }

        $character->load('stats'); // garante stats carregadas


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
        return DB::transaction(function () use ($userId) {
            Log::info("NOVO PERSONAGEM CRIADO");

            // === 1. Cria o character ===
            $character = Character::create([
                'user_id' => $userId,
                'name'    => "Hero_{$userId}",
            ]);

            // === 2. Cria stats iniciais ===
            $character->stats()->create([
                'hp'             => 1000,
                'level'          => 1,
                'strength'       => 10,
                'intelligence'   => 5,
                'defense_bonus'  => 8,
                'mdefense_bonus' => 8,
                'dexterity'      => 7,
                'stamina'        => 12,
            ]);

            // === 3. Cria inventários vazios ===
            $character->soulInventory()->create();
            $character->soulGridInventory()->create();
            $consumablesInventory = $character->consumablesInventory()->create();

            // === 3a. Cria o BattlePack inicial ===
            $battlePack = $character->battlePack()->create([
                'max_slots' => 4,
            ]);
            // === 3b. Adiciona consumíveis iniciais ao inventário ===
            $equippedSlots = [
                'Health Potion' => 0,
                'Stamina Tonic'   => 1,
            ];

            foreach (self::$defaultConsumables as $data) {
                $consumable = \App\Models\Consumable::firstOrCreate(
                    ['name' => $data['name']],
                    [
                        'description'  => $data['description'],
                        'effect_type'  => $data['effect_type'],
                        'effect_value' => $data['effect_value'],
                    ]
                );

                // cria o item no inventário
                $item = $consumablesInventory->items()->create([
                    'consumable_id' => $consumable->id,
                    'quantity'      => $data['quantity'],
                ]);

                // se estiver nos que devem ser equipados, cria slot no battlepack
                if (isset($equippedSlots[$data['name']])) {
                    $battlePack->slots()->create([
                        'consumable_item_id' => $item->id,
                        'slot_index'         => $equippedSlots[$data['name']],
                    ]);
                }
            }

            // === 4. Cria Skills iniciais no DB, se não existirem ===
            $this->createDefaultSkills();

            // === 5. Replica e equipa SoulGrid inicial ===
            $templateGrid = SoulGrid::where('name', 'Starter Grid')->first();
            if (!$templateGrid) {
                $templateGrid = SoulGrid::create([
                    'name'        => 'Starter Grid',
                    'slots_count' => 4,
                ]);
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

            $equippedGrid = $templateGrid->replicateForCharacter($character);

            // === 6. Cria algumas Souls iniciais e equipa na grid ===
            $initialSoulsData = [
                ['name' => 'Soul A', 'skills' => [1, 2, 3, 5]],
                ['name' => 'Soul B', 'skills' => [5, 2, 3, 7]],
            ];

            $soulsForRedis = [];
            foreach ($initialSoulsData as $soulData) {
                $soul = Soul::create(['name' => $soulData['name']]);
                $soul->skills()->sync($soulData['skills']);
                $equippedGrid->souls()->attach($soul->id);

                // Monta skills completas para o Redis
                $skillsArray = $soul->skills()->get()->map(fn($skill) => [
                    'id'          => $skill->id,
                    'name'        => $skill->name,
                    'type'        => $skill->type,
                    'power'       => $skill->power ?? 0,
                    'stamina_cost' => $skill->stamina_cost ?? 0,
                    'pre_delay'   => $skill->pre_delay ?? 0,
                    'post_delay'  => $skill->post_delay ?? 0,
                    'duration' => $skill->duration,
                    'level' => $skill->level ?? 1,
                    'stat' => $skill->stat,
                    'tick_interval' => $skill->interval,
                    'tick_skill_id' => $skill->tick_skill_id,
                    'tick_skill_flag' => $skill->tick_skill_flag,
                ])->toArray();

                // === Monta tick skills ===
                foreach ($skillsArray as $skill) {
                    if (!empty($skill['tick_skill_id'])) {
                        // skill que dispara tick skill (flag false)
                        $tickSkillModel = \App\Models\Skill::find((int)$skill['tick_skill_id']);
                        if ($tickSkillModel) {
                            $tickSkillsForRedis[$tickSkillModel->id] = [
                                'id' => $tickSkillModel->id,
                                'name' => $tickSkillModel->name,
                                'type' => $tickSkillModel->type,
                                'power' => $tickSkillModel->power ?? 0,
                                'stamina_cost' => $tickSkillModel->stamina_cost ?? 0,
                                'pre_delay' => $tickSkillModel->pre_delay ?? 0,
                                'post_delay' => $tickSkillModel->post_delay ?? 0,
                                'duration' => $tickSkillModel->duration,
                                'level' => $tickSkillModel->level ?? 1,
                                'stat' => $tickSkillModel->stat,
                                'tick_interval' => $tickSkillModel->tick_interval,
                                'tick_skill_id' => $tickSkillModel->tick_skill_id, // geralmente null
                                'tick_skill_flag' => true, // tick skill sempre true
                            ];
                        }
                    } elseif (!empty($skill['tick_skill_flag'])) {
                        // skill que já é tick skill
                        $tickSkillsForRedis[$skill['id']] = $skill;
                    }
                }

                $soulsForRedis[] = [
                    'id'     => $soul->id,
                    'name'   => $soul->name,
                    'skills' => $skillsArray,
                ];
            }



            // === 7. Salva grid e souls no Redis ===
            $gridKey = "battle:{$character->id}:character:{$character->id}:equipped_soul_grid";
            Redis::set($gridKey, json_encode($soulsForRedis, JSON_UNESCAPED_UNICODE));

            // === Salva tick skills separadas no Redis ===
            $tickSkillsKey = "battle:{$character->id}:character:{$character->id}:tick_skills";
            Redis::set($tickSkillsKey, json_encode(array_values($tickSkillsForRedis), JSON_UNESCAPED_UNICODE));

            // === 8. Salva character session no Redis ===
            $statsArray = $character->stats ? $character->stats->toArray() : [];
            Redis::hmset("character_session:{$character->id}", [
                'id'      => $character->id,
                'user_id' => $character->user_id,
                'name'    => $character->name,
                'stats'   => json_encode($statsArray, JSON_UNESCAPED_UNICODE),
            ]);

            return $character;
        });
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
