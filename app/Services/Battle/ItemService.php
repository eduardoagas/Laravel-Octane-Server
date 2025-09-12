<?php

namespace App\Services\Battle;

use App\Models\ConsumableItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\InsufficientStaminaException;
use SebastianBergmann\LinesOfCode\RuntimeException;

class ItemService
{
    private StaminaService $staminaService;

    /**
     * Fallback estático para consumables (similar ao skills)
     */
    private static array $consumables = [
        /* Exemplo:
        0 => [
            'id' => 0,
            'name' => 'Potion',
            'effect_type' => 'heal',  // 'heal', 'buff', 'debuff', etc
            'effect_value' => 20,
            'duration' => 5,          // em segundos, neutro/testável
            'lock_time' => 0,
            'level' => 1,
        ],*/];

    private string $battleLuaScript; // armazenará o conteúdo do Lua script


    public function __construct()
    {
        $this->staminaService = new StaminaService();

        // Carrega o Lua script uma vez no construtor
        $luaPath = storage_path("redis_scripts/battle_skill_indexed.lua");
        if (!file_exists($luaPath)) {
            throw new \RuntimeException("Lua script não encontrado em: $luaPath");
        }
        $this->battleLuaScript = file_get_contents($luaPath);
    }

    /**
     * Retorna o nome de um consumable
     */
    public static function getItemName(int $itemId, ?string $battleId = null, ?string $casterType = null, ?string $casterId = null): string
    {
        if ($battleId && $casterType && $casterId) {
            $c = self::findConsumableInRedisStatic($battleId, $casterType, $casterId, $itemId);
            if ($c) {
                return $c['name'] ?? 'Unknown Item';
            }
        }

        Log::error("Consumable $itemId fallback usado no array estático. Contexto:", [
            'battle_id' => $battleId,
            'caster_type' => $casterType,
            'caster_id' => $casterId,
        ]);

        return self::$consumables[$itemId]['name'] ?? 'Unknown Item';
    }


    /**
     * Versão estática do finder para métodos estáticos
     */
    private static function findConsumableInRedisStatic(string $battleId, string $casterType, string $casterId, int $itemId): ?array
    {
        $redisKey = "battle:{$battleId}:{$casterType}:{$casterId}:consumables";
        $itemsRaw = Redis::hgetall($redisKey);
        if (!$itemsRaw) return null;

        // Cada campo é id => json
        if (!isset($itemsRaw[$itemId])) return null;

        $item = json_decode($itemsRaw[$itemId], true);
        return is_array($item) ? $item : null;
    }



    /**
     * Aplica consumable ao alvo
     */
    public function applyConsumable(
        array $caster,
        array $target,
        string $battleId,
        int $itemId,
        string $casterType,
        string $targetType
    ): array {
        $casterId = (string)$caster['instanceId'];
        $item = $this->findConsumableInRedis($battleId, $casterType, $casterId, $itemId)
            ?? self::$consumables[$itemId] ?? null;
        if (!$item) throw new \InvalidArgumentException("Consumable {$itemId} not found");

        // normaliza targetKey
        $targetKey = ($targetType ?? 'character') === 'monster' ? 'monsters' : 'characters_data';
        $redisKey = "battle:$battleId:$targetKey";

        // --- Se for stamina, delegar ao PHP (uso do StaminaService) ---
        if (($item['effect_type'] ?? '') === 'stamina') {
            // amount: power positive => recover, negative => drain (mantém tua convenção)
            $amount = (float)($item['effect_value'] ?? 0);

            if ($amount > 0) {
                // cura stamina: passa valor negativo para o consumeStamina (que subtrai, logo vira soma)
                $amount = -$amount;
            }

            // Chama StaminaService (que internamente faz Redis::eval do consume_stamina.lua)
            // *Sugestão*: ajustar StaminaService::consumeStamina para retornar o decoded array com used/current_after.
            $stRes = StaminaService::consumeStamina($battleId, (string)$target['instanceId'], $amount, $targetType);

            if ($stRes === null || (isset($stRes['error']) && $stRes['error'])) {
                // tratar erro/insuficiente - comportamente que você preferir
                throw new \RuntimeException("Stamina operation failed: " . json_encode($stRes));
            }

            // se consumeStamina devolver somente float, adaptação: $currentAfter = $stRes (float)
            // se devolver array (recomendado), use $stRes['current_after']
            $currentAfter = is_array($stRes) ? ($stRes['current_after'] ?? null) : $stRes;

            // Atualiza o entity stats na hash de batalha para manter compatibilidade
            $rawEntity = Redis::hget($redisKey, $target['instanceId']);
            if ($rawEntity) {
                $entity = json_decode($rawEntity, true);
                if (!is_array($entity)) $entity = [];
                if (!isset($entity['stats']) || !is_array($entity['stats'])) $entity['stats'] = [];
                $entity['stats']['stamina'] = $currentAfter;
                Redis::hset($redisKey, $target['instanceId'], json_encode($entity));
            }

            return [
                'battle_id' => $battleId,
                'caster_id' => $casterId,
                'item_id' => $itemId,
                'stamina_delta' => $item['effect_value'] ?? $amount,
                'current_stamina' => $currentAfter,
                'lua_exec_ms' => null,
            ];
        }


        $evalResult = Redis::eval(
            $this->battleLuaScript,
            1,
            $redisKey,
            $item['effect_type'] ?? '',
            $casterId,
            $target['instanceId'],
            $item['effect_value'] ?? 0,
            $item['stat'] ?? '',
            $item['duration'] ?? null,
            $item['level'] ?? 1,
            $casterType,
            null, // tick_skill_id
            null, // tick_interval
            $battleId,
            '0', // stackable
            1,   // max_stacks
            'refresh', // stack_behavior
            $item['lock_time'] ?? null
        );

        $result = json_decode($evalResult, true);
        if (!$result) {
            throw new \RuntimeException("Invalid JSON from Lua script for consumable: " . substr((string)$evalResult, 0, 300));
        }

        return [
            'battle_id' => $battleId,
            'caster_id' => $casterId,
            'item_id' => $itemId,
            'target_hp' => $result['current_hp'] ?? null,
            'effect_applied' => $result['effect_applied'] ?? null,
            'lua_exec_ms' => $result['exec_time_ms'] ?? null,
            'damage_dealt' => $result['damage_dealt'] ?? null,
            'healed_amount' => $result['healed_amount'] ?? null,
            'buff_applied' => $result['buff_applied'] ?? null,
            'debuff_applied' => $result['debuff_applied'] ?? null,
            'debuff_chance' => $result['debuff_chance'] ?? null,
            'debuff_roll' => $result['debuff_roll'] ?? null,
            'debuff_failed' => $result['debuff_failed'] ?? null,
        ];
    }

    /**
     * Inicia uso do item (equivalente a startSkillCast)
     */
    public function startItemCast(
        array $caster,
        array $target,
        string $battleId,
        int $itemId,
        string $casterType,
        string $targetType
    ): void {
        $casterId = (string)$caster['instanceId'];

        $item = $this->findConsumableInRedis($battleId, $casterType, $casterId, $itemId)
            ?? self::$consumables[$itemId] ?? null;

        if (!$item) throw new \InvalidArgumentException("Consumable {$itemId} not found");

        $casterStats = $caster['stats'] ?? [];
        if (is_string($casterStats)) $casterStats = json_decode($casterStats, true);

        $totalMs = ($item['pre_delay'] ?? 0) + ($item['animation_time'] ?? 0) + ($item['post_delay'] ?? 0) + ($item['lock_time'] ?? 0);
        $ttlSec = max(1, (int)ceil($totalMs / 1000) + 1);

        // Apenas characters: NX para impedir enfileiramento duplicado
        if ($casterType === 'character') {
            $executionKey = "battle:{$battleId}:skill_in_execution:{$casterId}";
            $ok = Redis::set($executionKey, 1, 'NX', 'EX', $ttlSec);
            if (!$ok) {
                Log::channel('battle_debug')->debug("[startItemCast] Caster {$casterId} já está usando item, ignorando.");
                return;
            }
        }

        $eventId = uniqid('', true);
        $event = [
            'caster_id' => $casterId,
            'caster_type' => $casterType,
            'item_id' => $itemId,
            'target_id' => $target['instanceId'],
            'target_type' => $targetType,
            'phase' => 'pre_delay',
            'ready_at' => microtime(true) + (($item['pre_delay'] ?? 0) / 1000),
            'pre_delay' => $item['pre_delay'] ?? 0,
            'animation_time' => $item['animation_time'] ?? 0,
            'post_delay' => $item['post_delay'] ?? 0,
            'lock_time' => $item['lock_time'] ?? 0,
        ];

        // Chaves Redis - servirão tanto pr aitens quanto pra skills
        $zsetKey = "battle:{$battleId}:pending_skills_zset";   // ZSET com score = ready_at
        $hashKey = "battle:{$battleId}:pending_skills_data";   // HASH com eventId => JSON

        Redis::hset($hashKey, $eventId, json_encode($event));
        Redis::zadd($zsetKey, [$eventId => $event['ready_at']]);

        Log::info("ITEM EVENT CREATED: {$eventId} => " . json_encode($event));
    }

    private function findConsumableInRedis(string $battleId, string $casterType, string $casterId, int $itemId): ?array
    {
        $redisKey = "battle:{$battleId}:{$casterType}:{$casterId}:consumables";
        $rawItems = Redis::hgetall($redisKey);
        if (!$rawItems || !isset($rawItems[$itemId])) return null;

        $item = json_decode($rawItems[$itemId], true);
        return is_array($item) ? $item : null;
    }



    /**
     * Consome 1 (ou $amount) do consumable item (DB + Redis battle key + Redis preparatory key).
     *
     * @param string $battleId
     * @param string $playerInstanceId  // instanceId na batalha (ex: "1", "2" etc)
     * @param int $characterId         // DB character id (se disponível)
     * @param int $consumableItemId    // id da linha ConsumableItem no DB (isso que você tem no item['id'])
     * @param int $amount
     * @param string|null $prepRedisKey  // key preparatória onde também mantêm consumables (ex: "character_session:{$characterId}:consumables")
     *
     * @return array resultado com 'db' e 'redis' info
     * @throws \Exception
     */
    public function consumeItem(
        string $battleId,
        string $playerInstanceId,
        int $characterId,
        int $consumableItemId,
        int $amount = 1,
        ?string $prepRedisKey = null
    ): array {
        $lockKey = "battle:{$battleId}:consume_lock:char:{$characterId}:item:{$consumableItemId}";
        $lockAcquired = Redis::set($lockKey, time(), 'NX', 'PX', 2000); // 2s lock
        if (!$lockAcquired) {
            throw new RuntimeException("Could not acquire consume lock, try again");
        }

        try {
            // 1) DB: decrementa com lockForUpdate em transação
            $dbResult = DB::transaction(function () use ($consumableItemId, $amount) {
                $row = ConsumableItem::where('id', $consumableItemId)->lockForUpdate()->first();

                if (!$row) {
                    throw new \InvalidArgumentException("ConsumableItem {$consumableItemId} not found in DB");
                }

                $qty = (int)$row->quantity;

                if ($qty < $amount) {
                    throw new \RuntimeException("Not enough quantity in DB ({$qty} < {$amount})");
                }

                $newQty = $qty - $amount;
                if ($newQty > 0) {
                    $row->quantity = $newQty;
                    $row->save();
                } else {
                    // opcional: delete or set quantity=0
                    $row->delete();
                }

                // Retorna estado DB pra uso posterior
                return [
                    'old_qty' => $qty,
                    'new_qty' => $newQty,
                    'deleted' => $newQty <= 0,
                ];
            });

            // 2) Redis: decrementa tanto a key da batalha quanto a preparatória (se fornecida) com Lua atômico
            $battleKey = "battle:{$battleId}:character:{$playerInstanceId}:consumables";
            $keys = [$battleKey];
            if ($prepRedisKey) $keys[] = $prepRedisKey;

            $lua = file_get_contents(storage_path('redis_scripts/consume_item_multi_keys.lua'));
            // chama com KEYS = keys, ARGV = [consumableItemId, amount]
            $eval = Redis::eval($lua, count($keys), ...array_merge($keys, [$consumableItemId, $amount]));

            $redisResult = json_decode($eval, true);

            // 3) Verificação / reconcilliation: se algum key falhou (p.ex. item not found) logar e (opcional) reconciliar com DB
            foreach ($redisResult as $idx => $r) {
                $decoded = is_string($r) ? json_decode($r, true) : $r;
                if (!isset($decoded['found']) || $decoded['found'] === false) {
                    Log::warning("[ItemService::consumeItem] Redis update issue for key {$keys[$idx]}", [
                        'payload' => $decoded,
                        'consumableItemId' => $consumableItemId,
                        'battle' => $battleId,
                        'playerInstance' => $playerInstanceId,
                    ]);
                    // opcional: re-sync that Redis key from DB here (reconciliação)
                }
            }

            // 4) Opcional: Broadcast do novo inventory/consumables para o player/battle
            // BattleBroadcaster::broadcastToBattle(...) ou outra função que você use

            return [
                'db' => $dbResult,
                'redis' => $redisResult,
            ];
        } finally {
            // libera lock
            try {
                Redis::del($lockKey);
            } catch (\Throwable $_) {
            }
        }
    }
}
