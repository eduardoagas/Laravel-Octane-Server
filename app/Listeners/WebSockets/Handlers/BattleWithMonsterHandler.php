<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Monster;
use App\Models\Character; // <-- novo: precisamos do model Character aqui
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
        $characterId = isset($sessionData['character_id']) ? (int)$sessionData['character_id'] : null;

        if (!$characterId) {
            $connection->send(json_encode(['error' => 'Character ID not found in session']));
            Log::warning("No character_id found in session for user $userId and token $token");
            return;
        }

        // 2️⃣ Busca os dados do personagem (vêm como strings do Redis)
        $characterRaw = Redis::hgetall("character_session:$characterId");
        if (empty($characterRaw)) {
            $connection->send(json_encode(['error' => 'Character data not found']));
            Log::warning("Character data missing for character_id $characterId");
            return;
        }

        // normaliza stats (string JSON -> array, "Array" -> [], array -> array)
        $stats = $this->normalizeStatsValue($characterRaw['stats'] ?? null);

        // garante current_hp
        $stats['current_hp'] = $stats['hp'] ?? 0;
        // garante statuses
        if (!isset($stats['statuses']) || !is_array($stats['statuses'])) {
            $stats['statuses'] = [];
        }

        // monta payload limpo do personagem (array/obj) — usado para enviar e para persistir na estrutura da battle
        $characterPayload = [
            'id'         => $characterId,
            'user_id'    => isset($characterRaw['user_id']) ? (int)$characterRaw['user_id'] : null,
            'name'       => $characterRaw['name'] ?? null,
            'created_at' => $characterRaw['created_at'] ?? null,
            'updated_at' => $characterRaw['updated_at'] ?? null,
            'stats'      => $stats,
        ];

        // 3️⃣ Criar ID único para a batalha
        $battleId = uniqid('battle_', true);

        // 4️⃣ Definir instanceId incremental para o personagem
        $currentPlayers = Redis::hlen("battle:$battleId:characters_data");
        $playerInstanceId = $currentPlayers + 1;
        $characterPayload['instanceId'] = (string)$playerInstanceId;

        // 5️⃣ Registrar personagem na batalha
        Redis::sadd("battle:$battleId:characters", (string)$characterId);

        // grava um JSON da estrutura do personagem em characters_data
        Redis::hset("battle:$battleId:characters_data", (string)$characterId, json_encode($characterPayload, JSON_UNESCAPED_UNICODE));

        // 6️⃣ Vincular battle_instance_id na sessão
        Redis::hset("session:$token", 'battle_instance_id', $battleId);

        /*
         * === NOVO: Pré-carrega a SoulGrid equipada (com Souls e Skills) para uso rápido durante a batalha ===
         *
         * Racional:
         * - Para desempenho em combate, precisamos de acesso rápido ao grid equipado,
         *   as souls dentro dele e as skills de cada soul.
         * - Em vez de manter tudo persistido na sessão desde a conexão,
         *   carregamos essa estrutura no Redis apenas quando a batalha começa,
         *   sob a key específica do battle. Durante a batalha, atualizações
         *   podem escrever apenas nessa key, reduzindo I/O ao Postgres.
         */
        try {
            // Busca o Character completo com a relação equipada -> souls -> skills
            $characterModel = Character::with(['equippedSoulGrid.souls.skills'])->find($characterId);

            if ($characterModel && $characterModel->equippedSoulGrid) {
                $equippedGrid = $characterModel->equippedSoulGrid;

                // Normaliza para array (inclui souls.skills via eager load)
                $equippedGridArray = $equippedGrid->toArray();

                // Salva a estrutura completa do grid no Redis para esta batalha/character
                // Key: battle:$battleId:character:{$characterId}:equipped_soul_grid
                Redis::set("battle:$battleId:character:{$characterId}:equipped_soul_grid", json_encode($equippedGridArray, JSON_UNESCAPED_UNICODE));

                // Log para debug/performance
                Log::info("Equipped SoulGrid preloaded into Redis for battle", [
                    'battle' => $battleId,
                    'character_id' => $characterId,
                    'soul_grid_id' => $equippedGrid->id,
                ]);
            } else {
                // Sem grid equipada: registra no log (não é erro crítico)
                Log::info("No equipped SoulGrid found for character when starting battle", [
                    'character_id' => $characterId,
                    'battle' => $battleId,
                ]);
            }
        } catch (\Throwable $e) {
            // Erro no pré-carregamento: loga e continua (não bloqueia a criação da batalha)
            Log::error('Failed to preload equipped SoulGrid for battle', [
                'character_id' => $characterId,
                'battle' => $battleId,
                'error' => $e->getMessage(),
            ]);
        }
        /* === FIM NOVO === */

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

        // 7.1️⃣ Busca ou cria o monstro (model + stats relation)
        $monster = Monster::where('id', 1)->with('stats')->first();
        if (!$monster) {
            $monster = Monster::create([
                'id' => 1,
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

        // 7.2️⃣ Monta payload do monstro com current_hp dentro de stats (ARRAY, não string)
        $monsterStats = $monster->stats ? $monster->stats->toArray() : [];
        $monsterStats['current_hp'] = $monsterStats['hp'] ?? 100;
        // garante statuses
        if (!isset($monsterStats['statuses']) || !is_array($monsterStats['statuses'])) {
            $monsterStats['statuses'] = [];
        }

        $monsterPayload = [
            'monster_id' => $monster->id,
            'name'       => $monster->name,
            'type'       => $monster->type,
            'instanceId' => (string)$monsterInstanceId,
            'stats'      => $monsterStats, // mantém como array aqui
        ];

        // 7.3️⃣ Salva monstro no Redis como JSON string (ok), mas quando enviar -> decodificar
        Redis::hset("battle:$battleId:monsters", (string)$monsterInstanceId, json_encode($monsterPayload, JSON_UNESCAPED_UNICODE));

        $now = now()->timestamp;

        // 8️⃣ Inicializar stamina do monstro (salva JSON)
        $monsterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int)($monsterStats['stamina'] ?? 0),
            (int)($monsterStats['dexterity'] ?? 0)
        );
        Redis::hset("battle:$battleId:stamina_data", "monster:{$monsterInstanceId}", json_encode($monsterStaminaData, JSON_UNESCAPED_UNICODE));

        // 9️⃣ Inicializar stamina do personagem (salva JSON)
        $characterStaminaData = $this->staminaService->initializeStamina(
            $now,
            (int)($stats['stamina'] ?? 0),
            (int)($stats['dexterity'] ?? 0)
        );
        Redis::hset("battle:$battleId:stamina_data", "character:{$characterId}", json_encode($characterStaminaData, JSON_UNESCAPED_UNICODE));

        // 🔟 Enviar update para o jogador — envia ARRAYS/OBJETOS, não strings JSON
        $outPayload = [
            'event'   => 'updateYourself',
            'channel' => "character.{$characterId}",
            'data'    => [
                'players' => [
                    [
                        'instanceId'  => (string)$playerInstanceId,
                        'currentHp'   => (int)($stats['current_hp'] ?? ($stats['hp'] ?? 0)),
                        'staminaData' => $characterStaminaData,
                        'stats'       => $stats, // envia stats como objeto/array
                    ]
                ],
                'monsters' => [
                    [
                        'instanceId' => (string)$monsterInstanceId,
                        'monster_id' => $monster->id,
                        'name'       => $monster->name,
                        'stats'      => $monsterStats, // envia stat do monstro como array/obj
                    ]
                ],
                'general' => ['globalMessages' => ["Battle's started!"]],
            ],
        ];

        // Debug: confirma que stats são arrays no payload (não strings)
        Log::debug('BattleWithMonster OUT', $outPayload);

        $connection->send(json_encode($outPayload, JSON_UNESCAPED_UNICODE));

        // 11️⃣ Adiciona batalha ativa e log
        Redis::sadd('battles:active', $battleId);
        Log::info("Battle $battleId created and sent to character.$characterId for user $userId.");
    }

    /**
     * Normaliza um valor de "stats" que veio do Redis ou de outro lugar:
     * - array -> retorna array
     * - object -> cast para array
     * - string JSON -> json_decode -> array
     * - literal "Array" -> []
     */
    protected function normalizeStatsValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array)$value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        // Se já for JSON string normal: '{"hp":100,...}'
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Caso alguém tenha gravado literalmente "Array" no Redis
        if ($value === 'Array') {
            return [];
        }

        // Se for double-encoded (ex: "\"{...}\"") tenta trim extra quotes e decode
        $trimmed = trim($value, "\"'");
        $decoded2 = json_decode($trimmed, true);
        if (is_array($decoded2)) {
            return $decoded2;
        }

        return [];
    }
}
