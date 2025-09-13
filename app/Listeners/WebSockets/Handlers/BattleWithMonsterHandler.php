<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Skill;
use App\Models\Monster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use Laravel\Reverb\Contracts\Connection;
use App\Models\Character; // precisamos do model Character aqui

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
        $characterId = isset($sessionData['character_id']) ? (int)$sessionData['character_id'] : null;

        if (!$characterId) {
            $connection->send(json_encode(['error' => 'Character ID not found in session']));
            Log::warning("No character_id found in session for user $userId and token $token");
            return;
        }

        // 2️⃣ Busca os dados do personagem (sessão)
        $characterRaw = Redis::hgetall("character_session:$characterId");
        if (empty($characterRaw)) {
            $connection->send(json_encode(['error' => 'Character data not found']));
            Log::warning("Character data missing for character_id $characterId");
            return;
        }

        $stats = $this->normalizeStatsValue($characterRaw['stats'] ?? null);
        $stats['current_hp'] = $stats['hp'] ?? 0;



        $characterPayload = [
            'id' => $characterId,
            'user_id' => isset($characterRaw['user_id']) ? (int)$characterRaw['user_id'] : null,
            'name' => $characterRaw['name'] ?? null,
            'created_at' => $characterRaw['created_at'] ?? null,
            'updated_at' => $characterRaw['updated_at'] ?? null,
            'stats' => $stats,
        ];

        // 3️⃣ Criar ID único para a batalha
        $battleId = uniqid('battle_', true);

        // 4️⃣ Definir instanceId incremental para o personagem
        $currentPlayers = Redis::hlen("battle:$battleId:characters_data");
        $playerInstanceId = $currentPlayers + 1;
        $characterPayload['instanceId'] = (string)$playerInstanceId;

        // 5️⃣ Registrar personagem na batalha
        Redis::sadd("battle:$battleId:characters", (string)$characterId);
        Redis::sadd("battle:$battleId:characters_instances", (string)$playerInstanceId);
        Redis::hset("battle:$battleId:characters_data", (string)$playerInstanceId, json_encode($characterPayload, JSON_UNESCAPED_UNICODE));

        // 🔹 NOVO: mapeamento instanceId -> characterId
        $instanceMapKey = "battle:$battleId:instance_map";
        Redis::hset($instanceMapKey, (string)$playerInstanceId, $characterId);
        Log::info("Instance map updated", [
            'battle' => $battleId,
            'instance_id' => $playerInstanceId,
            'character_id' => $characterId
        ]);

        // 6️⃣ Vincular battle_instance_id na sessão
        Redis::hset("session:$token", 'battle_instance_id', $battleId);

        // ============================================================== //
        // === AQUI: carregar os dados do jogador A PARTIR DO REDIS "world" na inicialização ===
        // ============================================================== //
        $soulsArray = [];
        $tickSkillsForInstance = [];

        try {
            // keys no formato que você já usou antes:
            $equippedGridKey = "world:{$characterId}:character:{$characterId}:equipped_soul_grid";
            $tickSkillsKey = "world:{$characterId}:character:{$characterId}:tick_skills";
            $consumablesWorldKey = "world:{$characterId}:character:{$characterId}:consumables";

            $equippedGridRaw = Redis::get($equippedGridKey);
            $tickSkillsRaw = Redis::get($tickSkillsKey);
            $consumablesHash = Redis::hgetall($consumablesWorldKey);

            $soulsArray = $equippedGridRaw ? json_decode($equippedGridRaw, true) : [];
            $tickSkillsForInstance = $tickSkillsRaw ? collect(json_decode($tickSkillsRaw, true))->keyBy('id')->toArray() : [];

            // grava a estrutura completa do grid da instância (vinda do world)
            $instanceGridKey = "battle:$battleId:character:{$playerInstanceId}:equipped_soul_grid";
            Redis::set($instanceGridKey, json_encode($soulsArray, JSON_UNESCAPED_UNICODE));

            // grava tick_skills normalizadas na instância
            $instanceTickSkillsKey = "battle:$battleId:character:{$playerInstanceId}:tick_skills";
            $tickList = array_values($tickSkillsForInstance);
            Redis::set($instanceTickSkillsKey, json_encode($tickList, JSON_UNESCAPED_UNICODE));

            Log::info("Equipped SoulGrid and tick skills copied from world to instance", [
                'battle' => $battleId,
                'character_id' => $characterId,
                'souls_count' => count($soulsArray),
                'tick_skills_count' => count($tickList),
            ]);
        } catch (\Throwable $e) {
            // não bloqueia a batalha; salva vazios e loga
            $soulsArray = [];
            Redis::set("battle:$battleId:character:{$playerInstanceId}:equipped_soul_grid", json_encode([], JSON_UNESCAPED_UNICODE));
            Log::error("Failed to build equipped SoulGrid from Postgres (non-fatal)", [
                'character_id' => $characterId,
                'instance_id' => $playerInstanceId,
                'battle' => $battleId,
                'error' => $e->getMessage(),
            ]);
        }

        // === NOVO: grava tick_skills (normalizadas) na instância a partir do Postgres ===
        try {
            $instanceTickSkillsKey = "battle:$battleId:character:{$playerInstanceId}:tick_skills";
            $tickList = array_values($tickSkillsForInstance); // reindexa
            Redis::set($instanceTickSkillsKey, json_encode($tickList, JSON_UNESCAPED_UNICODE));
            Log::info("Tick skills saved to instance from Postgres-derived grid", [
                'battle' => $battleId,
                'character_id' => $characterId,
                'instance_id' => $playerInstanceId,
                'tick_skills_count' => count($tickList),
            ]);
        } catch (\Throwable $e) {
            Redis::set("battle:$battleId:character:{$playerInstanceId}:tick_skills", json_encode([], JSON_UNESCAPED_UNICODE));
            Log::error("Failed to save tick_skills to instance", [
                'battle' => $battleId,
                'character_id' => $characterId,
                'instance_id' => $playerInstanceId,
                'error' => $e->getMessage(),
            ]);
        }

        // determina slot inicial (tenta pegar preferred_soul_slot da sessão)
        $preferredSlot = isset($characterRaw['preferred_soul_slot']) ? (int)$characterRaw['preferred_soul_slot'] : 0;
        $activeSoul = $soulsArray[$preferredSlot] ?? null;

        if ($activeSoul) {
            Redis::set("battle:$battleId:character:{$playerInstanceId}:active_soul_id", $activeSoul['id']);
            Redis::set("battle:$battleId:character:{$playerInstanceId}:skills", json_encode($activeSoul['skills'] ?? [], JSON_UNESCAPED_UNICODE));

            Log::info("Active soul preloaded for battle (from world)", [
                'battle' => $battleId,
                'character_id' => $characterId,
                'instance_id' => $playerInstanceId,
                'active_soul_id' => $activeSoul['id'],
            ]);
        } else {
            Redis::set("battle:$battleId:character:{$playerInstanceId}:skills", json_encode([], JSON_UNESCAPED_UNICODE));
        }

        // 7️⃣ Criar monstro com instanceId incremental
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");
        $maxMonsterInstance = 0;
        foreach ($monstersRaw as $m) {
            $data = json_decode($m, true);
            if (isset($data['instanceId'])) $maxMonsterInstance = max($maxMonsterInstance, (int)$data['instanceId']);
        }
        $monsterInstanceId = $maxMonsterInstance + 1;

        // 7.1️⃣ Busca ou cria o monstro
        $monster = Monster::where('id', 1)->with('stats')->first();
        if (!$monster) {
            $monster = Monster::create([
                'id' => 1,
                'name' => 'Goblin',
                'type' => 'goblin',
            ]);
            $monster->stats()->create([
                'hp' => 1000,
                'level' => 1,
                'strength' => 12,
                'intelligence' => 6,
                'physical_defense' => 5,
                'dexterity' => 1,
                'stamina' => 25,
            ]);
            $monster->load('stats');
        }

        // 7.2️⃣ Monta payload do monstro
        $monsterStats = $monster->stats ? $monster->stats->toArray() : [];
        $monsterStats['current_hp'] = $monsterStats['hp'] ?? 100;


        $monsterPayload = [
            'monster_id' => $monster->id,
            'name' => $monster->name,
            'type' => $monster->type,
            'instanceId' => (string)$monsterInstanceId,
            'stats' => $monsterStats,
        ];

        Redis::hset("battle:$battleId:monsters", (string)$monsterInstanceId, json_encode($monsterPayload, JSON_UNESCAPED_UNICODE));

        $now = now()->timestamp;

        // 7.4️⃣ Pré-carrega as Skills do monstro (mantive sua lógica, já carregando tick skills corretas)
        try {
            $monsterWithSkills = Monster::with('skills')->find($monster->id);

            if ($monsterWithSkills && $monsterWithSkills->skills->isNotEmpty()) {
                $skillsArray = $monsterWithSkills->skills->toArray();
            } else {
                $attackSkill = Skill::find(1);
                $waitSkill = Skill::find(4);
                $poisonTick = Skill::find(6); // opcional, se existir
                $skillsArray = array_filter([$attackSkill, $waitSkill, $poisonTick]);
                $skillsArray = array_map(fn($s) => $s->toArray(), $skillsArray);
                Log::info("No skills found for monster; assigning Attack/Wait (and optional ticks) from DB", [
                    'monster_instance_id' => $monsterInstanceId,
                    'monster_id' => $monster->id,
                    'battle' => $battleId,
                ]);
            }

            Redis::set(
                "battle:$battleId:monster:{$monsterInstanceId}:skills",
                json_encode($skillsArray, JSON_UNESCAPED_UNICODE)
            );

            $monsterSkills = $skillsArray ?? [];
            $monsterTickSkills = [];

            // Carrega tick-skills referenciadas por skills do monstro
            foreach ($monsterSkills as $ms) {
                $tickSkillId = $ms['tick_skill_id'] ?? null;
                if ($tickSkillId) {
                    $tickModel = Skill::find((int)$tickSkillId);
                    if ($tickModel) {
                        $monsterTickSkills[$tickModel->id] = [
                            'id' => $tickModel->id,
                            'name' => $tickModel->name,
                            'type' => $tickModel->type,
                            'power' => $tickModel->power ?? 0,
                            'duration' => $tickModel->duration,
                            'stat' => $tickModel->stat,
                            'tick_interval' => $tickModel->tick_interval,
                            'tick_skill_id' => $tickModel->tick_skill_id ?? null,
                            'tick_skill_flag' => $tickModel->tick_skill_flag ?? null,
                        ];
                    } else {
                        Log::warning("Tick skill referenced by monster not found in DB", [
                            'battle' => $battleId,
                            'monster_instance_id' => $monsterInstanceId,
                            'base_skill_id' => $ms['id'] ?? null,
                            'tick_skill_id' => $tickSkillId,
                        ]);
                    }
                }
            }

            Redis::set(
                "battle:$battleId:monster:{$monsterInstanceId}:tick_skills",
                json_encode(array_values($monsterTickSkills), JSON_UNESCAPED_UNICODE)
            );

            Log::info("Tick skills preloaded for monster", [
                'battle' => $battleId,
                'monster_instance_id' => $monsterInstanceId,
                'tick_skills_count' => count($monsterTickSkills),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to preload monster skills for battle', [
                'monster_instance_id' => $monsterInstanceId,
                'monster_id' => $monster->id,
                'battle' => $battleId,
                'error' => $e->getMessage(),
            ]);
        }

        // === NOVO: carregar consumíveis equipados ===
        try {
            // grava consumables (hash) na instância para permitir decremento em runtime
            $instanceConsumablesKey = "battle:$battleId:character:{$playerInstanceId}:consumables";
            if (!empty($consumablesHash)) {
                // consumablesHash já está no formato id => jsonString (conforme sua função de setup)
                // Convert to flat array: [id1, json1, id2, json2, ...]
                $flat = [];
                foreach ($consumablesHash as $k => $v) {
                    $flat[] = (string)$k;
                    $flat[] = $v;
                }
                // Se usar predis/phpredis via facade, hset com múltiplos args funciona
                Redis::hset($instanceConsumablesKey, ...$flat);

                Log::info("Consumables copied from world to battle instance", [
                    'battle' => $battleId,
                    'character_id' => $characterId,
                    'instance_id' => $playerInstanceId,
                    'consumables_count' => count($consumablesHash),
                ]);
            } else {
                // garante que não reste lixo
                Redis::del($instanceConsumablesKey);
                Log::info("No consumables in world for character; instance consumables key removed", [
                    'battle' => $battleId,
                    'character_id' => $characterId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Failed to load consumables for battle instance", [
                'battle' => $battleId,
                'character_id' => $characterId,
                'instance_id' => $playerInstanceId,
                'error' => $e->getMessage(),
            ]);
        }


        // 8️⃣ Inicializar stamina do monstro
        $monsterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int)($monsterStats['stamina'] ?? 0),
            (int)($monsterStats['dexterity'] ?? 0)
        );
        Redis::hset("battle:$battleId:stamina_data", "monster:{$monsterInstanceId}", json_encode($monsterStaminaData, JSON_UNESCAPED_UNICODE));

        // 9️⃣ Inicializar stamina do personagem
        $characterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int)($stats['stamina'] ?? 0),
            (int)($stats['dexterity'] ?? 0)
        );
        Redis::hset("battle:$battleId:stamina_data", "character:{$playerInstanceId}", json_encode($characterStaminaData, JSON_UNESCAPED_UNICODE));

        //set your instance id
        $syiPayload = [
            'event' => 'setYourInstanceId',
            'channel' => "character.{$characterId}",
            'data' => ['instanceId' => (string)$playerInstanceId]
        ];
        Log::debug('Set Your Instance Id OUT', $syiPayload);
        $connection->send(json_encode($syiPayload, JSON_UNESCAPED_UNICODE));

        // 🔟 Enviar update para o jogador
        $outPayload = [
            'event' => 'updateYourself',
            'channel' => "character.{$characterId}",
            'data' => [
                'players' => [
                    [
                        'instanceId' => (string)$playerInstanceId,
                        'currentHp' => (int)($stats['current_hp'] ?? ($stats['hp'] ?? 0)),
                        'staminaData' => $characterStaminaData,
                        //'stats' => $stats,
                        'soulSlotIndex' => $preferredSlot,
                    ]
                ],
                'enemies' => [
                    [
                        'instanceId' => (string)$monsterInstanceId,
                        'nstatus' => '',
                        'isAlive' => true,
                        'monster_id' => $monster->id,
                        //'name' => $monster->name,
                        //'stats' => $monsterStats,
                    ]
                ],
                'general' => ['globalMessages' => ["Battle's started!"]],
            ],
        ];

        Log::debug('BattleWithMonster OUT', $outPayload);
        $connection->send(json_encode($outPayload, JSON_UNESCAPED_UNICODE));

        // 11️⃣ Adiciona batalha ativa e log
        Redis::sadd('battles:active', $battleId);
        Log::info("Battle $battleId created and sent to character.$characterId (instance {$playerInstanceId}) for user $userId.");
    }

    protected function normalizeStatsValue(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array)$value;
        if (!is_string($value) || $value === '') return [];

        $decoded = json_decode($value, true);
        if (is_array($decoded)) return $decoded;

        if ($value === 'Array') return [];

        $trimmed = trim($value, "\"'");
        $decoded2 = json_decode($trimmed, true);
        if (is_array($decoded2)) return $decoded2;

        return [];
    }
}
