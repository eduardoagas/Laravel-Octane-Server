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

        // 3️⃣ Criar ID único para a batalha
        $battleId = uniqid('battle_', true);

        // Vincular battle_instance_id na sessão
        Redis::hset("session:$token", 'battle_instance_id', $battleId);

        // === Carrega todos os jogadores que iniciam / entram na batalha ===
        // para agora: só o criador; futuramente passe um array com vários character ids
        $characterIdsToLoad = [$characterId]; // ajusta conforme necessário
        $charIdToInstance = $this->loadPlayers($battleId, $characterIdsToLoad); // retorna map characterId => instanceId


        $monstersToSpawn = [
            ['id' => 1, 'type' => 'goblin'],
        ];

        $monsterInstanceIds = $this->loadMonsters($battleId, $monstersToSpawn);

        // pega instanceId do criador da batalha
        $creatorInstanceId = $charIdToInstance[$characterId] ?? null;
        if ($creatorInstanceId) {
            $syiPayload = [
                'event' => 'setYourInstanceId',
                'channel' => "character.{$characterId}",
                'data' => ['instanceId' => (string)$creatorInstanceId]
            ];
            Log::debug('Set Your Instance Id OUT', $syiPayload);

            // envia via conexão (ou via BattleBroadcast se quiser propagar)
            $connection->send(json_encode($syiPayload, JSON_UNESCAPED_UNICODE));
        }

        $playersPayload = [];
        $enemiesPayload = [];

        foreach ($charIdToInstance as $cId => $pInstanceId) {
            // lê character data salvo em battle:<id>:characters_data
            $charRaw = Redis::hget("battle:$battleId:characters_data", (string)$pInstanceId);
            $charData = $charRaw ? json_decode($charRaw, true) : null;

            // stamina salvo em battle:<id>:stamina_data (hash)
            $staminaRaw = Redis::hget("battle:$battleId:stamina_data", "character:{$pInstanceId}");
            $staminaData = $staminaRaw ? json_decode($staminaRaw, true) : null;

            // deltas
            $deltaHp = json_decode(Redis::get("battle:$battleId:character:{$pInstanceId}:delta_hp") ?? '{}', true);
            $deltaStamina = json_decode(Redis::get("battle:$battleId:character:{$pInstanceId}:delta_stamina") ?? '{}', true);
            Log::debug('STAMINA OUT', [
                'battle' => $battleId,
                'field' => "character:{$pInstanceId}",
                'CharacterStaminaData' => $staminaData,
            ]);
            $playersPayload[] = [
                'instanceId' => (string)$pInstanceId,
                'currentHp' => (int)($charData['stats']['current_hp'] ?? ($charData['stats']['hp'] ?? 0)),
                'staminaData' => $staminaData,
                'soulSlotIndex' => isset($charData['stats']['preferred_soul_slot']) ? (int)$charData['stats']['preferred_soul_slot'] : (isset($charData['soulSlotIndex']) ? $charData['soulSlotIndex'] : 0),
                //'isAlive' => !($deltaHp['dead'] ?? false),
                /* 'deltaHp' => [
                    'lost' => $deltaHp['lost'] ?? 0,
                    'healed' => $deltaHp['healed'] ?? 0,
                ],
                'deltaStamina' => [
                    'used' => $deltaStamina['used'] ?? 0,
                ],*/
            ];
        }

        foreach ($monsterInstanceIds as $mInstanceId) {
            $monsterRaw = Redis::hget("battle:$battleId:monsters", (string)$mInstanceId);
            if (!$monsterRaw) continue;

            $monsterData = json_decode($monsterRaw, true);

            // Busca deltas do monstro
            /*$deltaHp = json_decode(Redis::get("battle:$battleId:monster:{$mInstanceId}:delta_hp") ?? '{}', true);
            $deltaStamina = json_decode(Redis::get("battle:$battleId:monster:{$mInstanceId}:delta_stamina") ?? '{}', true);*/

            $enemiesPayload[] = [
                'instanceId' => (string)$mInstanceId,
                'monster_id' => $monsterData['monster_id'] ?? null,
                'nstatus' => '',
                'isAlive' => true,
                // 'isAlive' => !($deltaHp['dead'] ?? false),
                /* 'deltaHp' => [
                    'lost' => $deltaHp['lost'] ?? 0,
                    'healed' => $deltaHp['healed'] ?? 0,
                ],
                'deltaStamina' => [
                    'used' => $deltaStamina['used'] ?? 0
                ],*/
                //'name' => $monsterData['name'] ?? null, // opcional
                //'stats' => $monsterData['stats'] ?? null, // opcional, caso queira enviar
            ];
        }


        // 🔟 Enviar update para o jogador
        $outPayload = [
            'event' => 'updateYourself',
            'channel' => "character.{$characterId}",
            'data' => [
                'players' => $playersPayload,
                'enemies' => $enemiesPayload,
                'general' => ['globalMessages' => ["Battle's started!"]],
            ],
        ];

        Log::debug('BattleWithMonster OUT', $outPayload);
        $connection->send(json_encode($outPayload, JSON_UNESCAPED_UNICODE));

        // 11️⃣ Adiciona batalha ativa e log
        Redis::sadd('battles:active', $battleId);
        Log::info("Battle $battleId created and sent to character.$characterId (instance {$creatorInstanceId}) for user $userId.");
    }

    /**
     * Inicializa múltiplos jogadores na batalha a partir do "world" / character_session
     *
     * @param string $battleId
     * @param int[] $characterIds
     * @return array Map de characterId => instanceId
     */
    protected function loadPlayers(string $battleId, array $characterIds): array
    {
        $result = [];

        // pega quantos players já existem para gerar instanceIds incrementais
        $currentPlayers = Redis::hlen("battle:$battleId:characters_data");
        $nextInstanceId = $currentPlayers + 1;

        $now = now()->timestamp;

        foreach ($characterIds as $characterId) {
            $characterRaw = Redis::hgetall("character_session:{$characterId}");
            $stats = $this->normalizeStatsValue($characterRaw['stats'] ?? null);
            $stats['current_hp'] = $stats['hp'] ?? 0;

            $playerInstanceId = $nextInstanceId++;
            $characterPayload = [
                'id' => $characterId,
                'user_id' => isset($characterRaw['user_id']) ? (int)$characterRaw['user_id'] : null,
                'name' => $characterRaw['name'] ?? null,
                'created_at' => $characterRaw['created_at'] ?? null,
                'updated_at' => $characterRaw['updated_at'] ?? null,
                'stats' => $stats,
                'instanceId' => (string)$playerInstanceId,
            ];

            // Registrar personagem na batalha
            Redis::sadd("battle:$battleId:characters", (string)$characterId);
            Redis::sadd("battle:$battleId:characters_instances", (string)$playerInstanceId);
            Redis::hset("battle:$battleId:characters_data", (string)$playerInstanceId, json_encode($characterPayload, JSON_UNESCAPED_UNICODE));

            // Mapeamento instanceId -> characterId
            Redis::hset("battle:$battleId:instance_map", (string)$playerInstanceId, $characterId);

            Log::info("Instance map updated", [
                'battle' => $battleId,
                'instance_id' => $playerInstanceId,
                'character_id' => $characterId
            ]);


            // Inicializar stamina (salva em battle:<id>:stamina_data character:<instance>)
            $characterStaminaData = $this->staminaService->initializeStamina(
                $now,
                (int)($stats['stamina'] ?? 0),
                (int)($stats['dexterity'] ?? 0),
                $battleId,
                "character:{$playerInstanceId}",
                2 // step da LUT (ajuste se quiser)
            );
            Redis::hset("battle:$battleId:stamina_data", "character:{$playerInstanceId}", json_encode($characterStaminaData, JSON_UNESCAPED_UNICODE));

            // Inicializar deltas de HP e Stamina para o player
            $deltaHpKey = "battle:$battleId:character:{$playerInstanceId}:delta_hp";
            $deltaStaminaKey = "battle:$battleId:character:{$playerInstanceId}:delta_stamina";

            Redis::set($deltaHpKey, json_encode([
                'lost' => 0,
                'hp_max' => $stats['hp'],
                'dead' => false
            ], JSON_UNESCAPED_UNICODE));

            Redis::set($deltaStaminaKey, json_encode([
                'used' => 0
            ], JSON_UNESCAPED_UNICODE));

            // ===========================================================
            // Carregar dados do world (soul grid, tick skills, consumables)
            // ===========================================================
            try {
                $equippedGridKey = "world:{$characterId}:character:{$characterId}:equipped_soul_grid";
                $tickSkillsKey    = "world:{$characterId}:character:{$characterId}:tick_skills";
                $consumablesKey   = "world:{$characterId}:character:{$characterId}:consumables";

                $equippedGridRaw = Redis::get($equippedGridKey);
                $tickSkillsRaw   = Redis::get($tickSkillsKey);
                $consumablesHash = Redis::hgetall($consumablesKey);

                $soulsArray = $equippedGridRaw ? json_decode($equippedGridRaw, true) : [];
                $tickSkillsForInstance = $tickSkillsRaw ? collect(json_decode($tickSkillsRaw, true))->keyBy('id')->toArray() : [];

                // grava estrutura do grid na instância
                Redis::set("battle:$battleId:character:{$playerInstanceId}:equipped_soul_grid", json_encode($soulsArray, JSON_UNESCAPED_UNICODE));

                // grava tick_skills na instância
                Redis::set("battle:$battleId:character:{$playerInstanceId}:tick_skills", json_encode(array_values($tickSkillsForInstance), JSON_UNESCAPED_UNICODE));

                // grava consumables (hash) na instância
                if (!empty($consumablesHash)) {
                    $flat = [];
                    foreach ($consumablesHash as $k => $v) {
                        $flat[] = (string)$k;
                        $flat[] = $v;
                    }
                    Redis::hset("battle:$battleId:character:{$playerInstanceId}:consumables", ...$flat);
                } else {
                    Redis::del("battle:$battleId:character:{$playerInstanceId}:consumables");
                }
            } catch (\Throwable $e) {
                // fallback seguro
                Redis::set("battle:$battleId:character:{$playerInstanceId}:equipped_soul_grid", json_encode([], JSON_UNESCAPED_UNICODE));
                Redis::set("battle:$battleId:character:{$playerInstanceId}:tick_skills", json_encode([], JSON_UNESCAPED_UNICODE));
                Redis::del("battle:$battleId:character:{$playerInstanceId}:consumables");
                Log::error("Failed to load world data for character {$characterId}", [
                    'battle' => $battleId,
                    'character_id' => $characterId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Define active soul/skills (fallback para vazio)
            $preferredSlot = isset($characterRaw['preferred_soul_slot']) ? (int)$characterRaw['preferred_soul_slot'] : 0;
            $activeSoul = $soulsArray[$preferredSlot] ?? null;

            if ($activeSoul) {
                Redis::set("battle:$battleId:character:{$playerInstanceId}:active_soul_id", $activeSoul['id']);
                Redis::set("battle:$battleId:character:{$playerInstanceId}:skills", json_encode($activeSoul['skills'] ?? [], JSON_UNESCAPED_UNICODE));
            } else {
                Redis::set("battle:$battleId:character:{$playerInstanceId}:skills", json_encode([], JSON_UNESCAPED_UNICODE));
            }

            // devolve mapping
            $result[$characterId] = $playerInstanceId;
        }
        return $result;
    }

    /**
     * Inicializa múltiplos monstros na batalha
     * 
     * @param string $battleId
     * @param array $monstersToSpawn Array de arrays: ['id' => X, 'type' => 'goblin']
     * @return array Lista de instanceIds criados
     */
    public function loadMonsters(string $battleId, array $monstersToSpawn): array
    {
        $instanceIds = [];

        // Busca último instanceId para não sobrescrever
        $monstersRaw = Redis::hgetall("battle:$battleId:monsters");
        $maxMonsterInstance = 0;
        foreach ($monstersRaw as $m) {
            $data = json_decode($m, true);
            if (isset($data['instanceId'])) $maxMonsterInstance = max($maxMonsterInstance, (int)$data['instanceId']);
        }

        $now = now()->timestamp;

        foreach ($monstersToSpawn as $mData) {
            $monsterInstanceId = ++$maxMonsterInstance;

            // Busca ou cria monstro
            $monster = Monster::with('stats', 'skills')->find($mData['id']);
            if (!$monster) {
                $monster = Monster::create([
                    'id' => $mData['id'],
                    'name' => ucfirst($mData['type']),
                    'type' => $mData['type'],
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

            // Inicializa stamina
            $monsterStaminaData = $this->staminaService->initializeStamina(
                $now,
                (int)($monsterStats['stamina'] ?? 0),
                (int)($monsterStats['dexterity'] ?? 0),
                $battleId,
                "character:{$monsterInstanceId}",
                1 // step da LUT (ajuste se quiser)
            );
            Redis::hset("battle:$battleId:stamina_data", "monster:{$monsterInstanceId}", json_encode($monsterStaminaData, JSON_UNESCAPED_UNICODE));

            // Inicializa deltas
            $deltaHpKey = "battle:$battleId:monster:{$monsterInstanceId}:delta_hp";
            $deltaStaminaKey = "battle:$battleId:monster:{$monsterInstanceId}:delta_stamina";

            Redis::set($deltaHpKey, json_encode([
                'lost' => 0,
                'hp_max' => $monsterStats['hp'],
                'dead' => false
            ], JSON_UNESCAPED_UNICODE));

            Redis::set($deltaStaminaKey, json_encode([
                'used' => 0
            ], JSON_UNESCAPED_UNICODE));

            // Pré-carrega skills
            try {
                $skillsArray = [];
                if ($monster->skills->isNotEmpty()) {
                    $skillsArray = $monster->skills->toArray();
                } else {
                    $attackSkill = Skill::find(1);
                    $waitSkill = Skill::find(4);
                    $poisonTick = Skill::find(6); // opcional
                    $skillsArray = array_filter([$attackSkill, $waitSkill, $poisonTick]);
                    $skillsArray = array_map(fn($s) => $s->toArray(), $skillsArray);
                }

                Redis::set(
                    "battle:$battleId:monster:{$monsterInstanceId}:skills",
                    json_encode($skillsArray, JSON_UNESCAPED_UNICODE)
                );

                // Tick-skills
                $monsterTickSkills = [];
                foreach ($skillsArray as $ms) {
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
                        }
                    }
                }

                Redis::set(
                    "battle:$battleId:monster:{$monsterInstanceId}:tick_skills",
                    json_encode(array_values($monsterTickSkills), JSON_UNESCAPED_UNICODE)
                );
            } catch (\Throwable $e) {
                Log::error('Failed to preload monster skills', [
                    'monster_instance_id' => $monsterInstanceId,
                    'monster_id' => $monster->id,
                    'battle' => $battleId,
                    'error' => $e->getMessage(),
                ]);
            }

            $instanceIds[] = $monsterInstanceId;
        }

        return $instanceIds;
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
