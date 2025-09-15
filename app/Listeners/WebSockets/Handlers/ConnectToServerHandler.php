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

        // --- SALVA OU ATUALIZA registros WORLD SEMPRE ---
        $helpers = new CharacterHelpers();
        $helpers->applyVitStatsToCharacter($character);
        $helpers->applyWisdomStatsToCharacter($character);

        // --- Se já tiver inventário, apenas atualiza; se não, cria ---

        // --- Inventários ---
        $characterSoulInventory = $character->soulInventory ?? $character->soulInventory()->create();
        $characterSoulGridInventory = $character->soulGridInventory ?? $character->soulGridInventory()->create();
        $consumablesInventory = $character->consumablesInventory ?? $character->consumablesInventory()->create();
        $battlePack = $character->battlePack ?? $character->battlePack()->create(['max_slots' => 4]);
        $helpers->setupConsumables($consumablesInventory, $battlePack, $character);
        $helpers->setupSkillsAndSouls($character);

        // --- Monta payload 'subscribeMe' para Unity ---
        $characterPayload = [
            'id'    => $character->id,
            'name'  => $character->name,
        ];

        $payloadToSend = [
            'event' => 'subscribeMe',
            'data'  => [
                'channel' => "character.{$character->id}",
                'character' => $characterPayload,
            ],
        ];

        Log::debug('WS OUT (subscribeMe)', $payloadToSend);
        $connection->send(json_encode($payloadToSend, JSON_UNESCAPED_UNICODE));

        Log::info("Conexão inicial completa: subscribeMe enviado", ['character_id' => $character->id]);
    }



    private function createCharacter(int $userId): Character
    {
        return DB::transaction(function () use ($userId) {
            Log::info("NOVO PERSONAGEM CRIADO");

            $character = Character::firstOrCreate([
                'user_id' => $userId,
                'name'    => "Hero_{$userId}",
            ]);

            // garante que só cria stats se ainda não tiver
            if (!$character->stats) {
                $character->stats()->create([
                    'level' => 1,
                    'strength'       => 10,
                    'intelligence'   => 5,
                    'physical_defense'  => 3,
                    'magical_defense'   => 3,
                    'dexterity'      => 4,
                    'stamina'        => 10,
                    'vitality'  => 1,
                    'wisdom' => 1,
                ]);
            }


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
            'power' => 10, //preset a = 10/30/60
            'stamina_cost' => 25, //preset a = 25/50/140
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 1,
        ],
        2 => [
            'id' => 2,
            'name' => 'Raise Defense',
            'type' => 'buff',
            'stat' => 'vitality_defense_bonus',
            'power' => 200,
            'duration' => 8,
            'stamina_cost' => 10,
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 1,
            /*'add_effects' => [
                [
                    'stat' => 'physical_damage_resistance',
                    'value' => 100
                ],
            ],*/
        ],
        3 => [
            'id' => 3,
            'name' => 'Heal',
            'type' => 'heal',
            'power' => 10,
            'stamina_cost' => 25,
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 1,
        ],
        4 => [
            'id' => 4,
            'name' => 'Wait',
            'type' => 'buff',
            'stat' => 'physical_defense',
            'power' => 0,
            'duration' => 3,
            'stamina_cost' => 0,
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 1,
        ],
        5 => [
            'id' => 5,
            'name' => 'Death',
            'type' => 'debuff',
            'stat' => 'death',
            'power' => 20,
            'stamina_cost' => 10,
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 1,
        ],
        6 => [
            'id' => 6,
            'name' => 'Poison Tick',
            'type' => 'physicalPurePercentageDamage',
            'power' => 6.25,
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
            'stat' => 'poison',
            'power' => 0,
            'duration' => null,
            'stamina_cost' => 25,
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 3,
            'tick_skill_id' => 6,
            'tick_interval' => 5,
        ],
        8 => [
            'id' => 1,
            'name' => 'Double Shot',
            'type' => 'physical',
            'power' => 4, //preset a = 10/30/60
            'stamina_cost' => 25, //preset a = 25/50/140
            'pre_delay' => 0,
            'post_delay' => 0,
            'level' => 1,
            'hits' => 2,
            'hit_delay' => 300,
        ],
    ];

    /**
     * Calcula stamina a partir do level e wisdom
     */
    public function calculateStamina(int $level, int $wisdom, float $staminaBonus = 0): int
    {
        return (int) $staminaBonus + (25 + (($level == 0 ? 1 : $level) * 1.2) + ((1 + ($wisdom == 0 ? 1 : $wisdom)) * 2));

    }

    /**
     * Aplica a stamina baseada em wisdom no personagem
     */
    public function applyWisdomStatsToCharacter(Character $character): void
    {
        $stats = $character->stats;

        if (!$stats) {
            Log::warning("Personagem {$character->id} não possui stats definidos.");
            return;
        }

        $stats->stamina = $this->calculateStamina($stats->level, $stats->wisdom);
        $stats->save();

        // Atualiza Redis
        $characterId = $character->id;
        $key = "character_session:{$characterId}";
        Redis::hmset($key, [
            'stats' => json_encode($stats->toArray(), JSON_UNESCAPED_UNICODE),
        ]);

        Log::info("Stamina derivada de WIS aplicada ao personagem {$characterId}", [
            'level' => $stats->level,
            'wisdom' => $stats->wisdom,
            'stamina' => $stats->stamina,
        ]);
    }


    private function calculateDefenseFromVit(int $level, int $vit, int $intelligence = 0, float $physicalDefBonus = 0, float $magicalDefBonus = 0, int $vitDef = 0, $intDef = 0, $hp_bonus): array
    {
        // Coeficientes calibrados (sincronizar com Lua)
        $A = 3.703913650809579;
        $B = 2.6906470089863075;
        $DEF_base = 5.0;
        $k_def = 1.0;
        $MDEF_base = 2.0;

        $vit = max(2, $vit + $vitDef);

        $HPMax = 50 + ($A * $vit + $B * pow($vit, 1.5)) + ($level * 15) + $hp_bonus;
        $DEF   = $DEF_base + $k_def * $vit + $physicalDefBonus;
        $MDEF  = $MDEF_base + 0.5 * $k_def * $vit + 0.5 * ($intelligence + $intDef) + $magicalDefBonus;

        return [
            'hp' => (float)$HPMax,
            'physical_defense' => (float)$DEF,
            'magical_defense' => (float)$MDEF,
        ];
    }

    public function applyVitStatsToCharacter(Character $character): void
    {


        $stats = $character->stats;

        if (!$stats) {
            Log::warning("Personagem {$character->id} não possui stats definidos.");
            return;
        }

        // Calcula stats derivados de Vitality
        $derived = $this->calculateDefenseFromVit($stats->level, $stats->vitality, $stats->intelligence, 0, 0, 0, 0, $stats->hp_bonus);

        // Atualiza stats do personagem
        $stats->hp = (int) round($derived['hp']);
        $stats->physical_defense = (int) round($derived['physical_defense']);
        $stats->magical_defense = (int) round($derived['magical_defense']);
        $stats->save();

        // Atualiza Redis
        $characterId = $character->id;
        $key = "character_session:{$characterId}";
        Redis::hmset($key, [
            'stats' => json_encode($stats->toArray(), JSON_UNESCAPED_UNICODE),
        ]);

        Log::info("Stats derivados de VIT aplicados ao personagem {$characterId}", $derived);
    }


    public function setupConsumables($inventory, $battlePack, Character $character)
    {
        $equippedSlots = [
            'Health Potion' => 0,
            'Stamina Tonic' => 1,
        ];

        $characterId = $character->id;
        $consumablesKey = "world:{$characterId}:character:{$characterId}:consumables";

        $consumablesForRedis = [];

        foreach (self::$defaultConsumables as $data) {
            // Consumable global (definição)
            $consumable = Consumable::firstOrCreate(
                ['name' => $data['name']],
                [
                    'description'  => $data['description'],
                    'effect_type'  => $data['effect_type'],
                    'effect_value' => $data['effect_value'],
                ]
            );

            // Item no inventário do personagem
            $item = $inventory->items()
                ->where('consumable_id', $consumable->id)
                ->first();

            if (!$item) {
                $item = $inventory->items()->create([
                    'consumable_id' => $consumable->id,
                    'quantity'      => $data['quantity'],
                ]);
            }

            // Slot equipado no battle pack
            $slotIndex = $equippedSlots[$data['name']] ?? null;
            if ($slotIndex !== null) {
                $battlePack->slots()
                    ->firstOrCreate(
                        ['slot_index' => $slotIndex],
                        ['consumable_item_id' => $item->id]
                    );
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

        // Salva no Redis
        Redis::hset($consumablesKey, ...collect($consumablesForRedis)->map(function ($c) {
            return [$c['id'], json_encode($c, JSON_UNESCAPED_UNICODE)];
        })->flatten()->toArray());
    }


    public function setupSkillsAndSouls(Character $character)
    {
        $skills = collect(self::$defaultSkills);

        // Skills (não recriar)
        foreach ($skills as $id => $skillData) {
            $addEffects = $skillData['add_effects'] ?? [];
            unset($skillData['add_effects']);

            $skillData['id'] = $id;
            $skill = Skill::firstOrCreate(['id' => $id], $skillData);

            foreach ($addEffects as $effect) {
                $skill->addEffects()->firstOrCreate($effect);
            }
        }

        // Soul grid base
        $templateGrid = SoulGrid::firstOrCreate(
            ['name' => 'Starter Grid'],
            ['slots_count' => 4]
        );

        if (!$templateGrid->stats()->exists()) {
            $templateGrid->stats()->create([
                'hp_bonus'              => 50,
                'strength'        => 5,
                'intelligence'    => 3,
                'physical_defense' => 2,
                'magical_defense' => 2,
                'dexterity'       => 2,
                'stamina'         => 5,
            ]);
        }

        // Checar se personagem já tem grid equipado
        $equippedGrid = $character->equippedSoulGrid;
        if (!$equippedGrid) {
            $equippedGrid = $templateGrid->replicateForCharacter($character);
        }

        // Souls iniciais
        $initialSoulsData = [
            ['name' => 'Soul A', 'skills' => [1, 2, 3, 7]],
            ['name' => 'Soul B', 'skills' => [1, 8, 3, 5]],
        ];

        $tickSkillsForRedis = [];
        $soulsForRedis = [];

        foreach ($initialSoulsData as $soulData) {
            $soul = Soul::firstOrCreate(['name' => $soulData['name']]);
            $soul->skills()->syncWithoutDetaching($soulData['skills']);

            if (!$equippedGrid->souls()->where('souls.id', $soul->id)->exists()) {
                $equippedGrid->souls()->attach($soul->id);
            }

            $skillsArray = $soul->skills()->get()->map(fn($skill) => [
                'id'             => $skill->id,
                'name'           => $skill->name,
                'type'           => $skill->type,
                'power'          => $skill->power ?? 0,
                'stamina_cost'   => $skill->stamina_cost ?? 0,
                'pre_delay'      => $skill->pre_delay ?? 0,
                'post_delay'     => $skill->post_delay ?? 0,
                'duration'       => $skill->duration ?? null,
                'level'          => $skill->level ?? 1,
                'stat'           => $skill->stat ?? null,
                'tick_interval'  => $skill->tick_interval ?? null,
                'tick_skill_id'  => $skill->tick_skill_id ?? null,
                'tick_skill_flag' => $skill->tick_skill_flag ?? false,
                'add_effects'    => $skill->addEffects->map(fn($effect) => [
                    'stat' => $effect->stat,
                    'value' => $effect->value,
                ])->toArray(),
            ])->toArray();

            foreach ($skillsArray as $skill) {
                // Adiciona a própria skill se for tick
                if (!empty($skill['tick_skill_flag'])) {
                    $tickSkillsForRedis[$skill['id']] = $skill;
                }

                // Adiciona a skill referenciada se existir
                if (!empty($skill['tick_skill_id'])) {
                    $tickSkill = Skill::find($skill['tick_skill_id']);
                    if ($tickSkill) {
                        $tickSkillsForRedis[$tickSkill->id] = [
                            'id' => $tickSkill->id,
                            'name' => $tickSkill->name,
                            'type' => $tickSkill->type,
                            'power' => $tickSkill->power ?? 0,
                            'stamina_cost' => $tickSkill->stamina_cost ?? 0,
                            'pre_delay' => $tickSkill->pre_delay ?? 0,
                            'post_delay' => $tickSkill->post_delay ?? 0,
                            'duration' => $tickSkill->duration ?? null,
                            'level' => $tickSkill->level ?? 1,
                            'stat' => $tickSkill->stat ?? null,
                            'tick_interval' => $tickSkill->tick_interval ?? null,
                            'tick_skill_id' => $tickSkill->tick_skill_id ?? null,
                            'tick_skill_flag' => $tickSkill->tick_skill_flag ?? false,
                            'add_effects' => $tickSkill->addEffects->map(fn($effect) => [
                                'stat' => $effect->stat,
                                'value' => $effect->value,
                            ])->toArray(),
                        ];
                    }
                }
            }

            $soulsForRedis[] = [
                'id'     => $soul->id,
                'name'   => $soul->name,
                'skills' => $skillsArray,
            ];
        }

        // Redis updates
        $characterId = $character->id;
        Redis::set("world:{$characterId}:character:{$characterId}:equipped_soul_grid", json_encode($soulsForRedis, JSON_UNESCAPED_UNICODE));
        Redis::set("world:{$characterId}:character:{$characterId}:tick_skills", json_encode(array_values($tickSkillsForRedis), JSON_UNESCAPED_UNICODE));
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
