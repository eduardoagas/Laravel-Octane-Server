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
        $casterId = (string)($caster['instanceId'] ?? ($caster['id'] ?? ''));
        $item = $this->findConsumableInRedis($battleId, $casterType, $casterId, $itemId)
            ?? self::$consumables[$itemId] ?? null;
        if (!$item) throw new \InvalidArgumentException("Consumable {$itemId} not found");

        // normaliza targetKey
        $targetKey = ($targetType ?? 'character') === 'monster' ? 'monsters' : 'characters_data';
        $redisKey = "battle:$battleId:$targetKey";

        // --- Se for stamina, delegar ao PHP (uso do StaminaService) ---
        if (($item['effect_type'] ?? '') === 'stamina') {
            $amount = (float)($item['effect_value'] ?? 0);

            if ($amount > 0) {
                // healing stamina: consumeStamina expects negative to add (convention kept)
                $amount = -$amount;
            }

            $stRes = StaminaService::consumeStamina($battleId, (string)$target['instanceId'], $amount, $targetType);

            if ($stRes === null || (isset($stRes['error']) && $stRes['error'])) {
                throw new \RuntimeException("Stamina operation failed: " . json_encode($stRes));
            }

            $currentAfter = is_array($stRes) ? ($stRes['current_after'] ?? null) : $stRes;

            // Atualiza entity.stats.stamina para compatibilidade
            $rawEntity = Redis::hget($redisKey, (string)$target['instanceId']);
            if ($rawEntity) {
                $entity = json_decode($rawEntity, true) ?: [];
                if (!isset($entity['stats']) || !is_array($entity['stats'])) $entity['stats'] = [];
                $entity['stats']['stamina'] = $currentAfter;
                Redis::hset($redisKey, (string)$target['instanceId'], json_encode($entity, JSON_UNESCAPED_UNICODE));
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

        // --- Para os outros tipos, usamos o BattleSkillProcessor em PHP ---
        // Precisamos montar um "skill-like" array que o processor entende.
        $skillLike = [
            'id' => $item['id'] ?? $itemId,
            'type' => $item['effect_type'] ?? 'buff', // heal/buff/debuff/physical/magical/...
            'power' => $item['effect_value'] ?? 0,
            'stat' => $item['stat'] ?? ($item['effect_stat'] ?? ''),
            'duration' => $item['duration'] ?? null,
            'level' => $item['level'] ?? 1,
            'tick_skill_id' => $item['tick_skill_id'] ?? null,
            'tick_interval' => $item['tick_interval'] ?? null,
            'stackable' => $item['stackable'] ?? false,
            'max_stacks' => $item['max_stacks'] ?? 1,
            'stack_behavior' => $item['stack_behavior'] ?? 'refresh',
            'lock_time' => $item['lock_time'] ?? 0,
            'add_effects' => $item['add_effects'] ?? [],
        ];

        // Garantir que tanto caster quanto target venham com a estrutura esperada (com stats)
        $casterEntity = $this->loadEntityForProcessor($battleId, $casterType, $caster);
        $targetEntity = $this->loadEntityForProcessor($battleId, $targetType, $target);

        // Processa via BattleSkillProcessor (PHP)
        $processor = new BattleSkillProcessor();
        $options = [
            'parentSkillId' => $skillLike['id'],
            'addEffects' => $skillLike['add_effects'],
            'lock_time' => $skillLike['lock_time'],
        ];

        $res = $processor->processSkill(
            $skillLike,
            $casterEntity,
            $targetEntity,
            $battleId,
            $casterType,
            $targetType,
            $options
        );

        // normaliza retorno para compatibilidade com versão antiga
        return [
            'battle_id' => $battleId,
            'caster_id' => $casterId,
            'item_id' => $itemId,
            'target_hp' => $res['current_hp'] ?? null,
            'effect_applied' => $res['buff_applied'] ?? $res['debuff_applied'] ?? null,
            'lua_exec_ms' => $res['exec_time_ms'] ?? null,
            'damage_dealt' => $res['damage_dealt'] ?? null,
            'healed_amount' => $res['healed_amount'] ?? null,
            'buff_applied' => $res['buff_applied'] ?? null,
            'debuff_applied' => $res['debuff_applied'] ?? null,
            'debuff_chance' => $res['debuff_chance'] ?? null,
            'debuff_roll' => $res['debuff_roll'] ?? null,
            'debuff_failed' => $res['debuff_failed'] ?? null,
        ];
    }

    private function loadEntityForProcessor(string $battleId, string $type, array $entityCandidate): array
    {
        // se já contém stats array, retorna
        if (!empty($entityCandidate['stats']) && is_array($entityCandidate['stats'])) {
            return $entityCandidate;
        }

        $inst = (string)($entityCandidate['instanceId'] ?? ($entityCandidate['id'] ?? ''));
        if ($inst === '') return $entityCandidate;

        $hash = ($type === 'monster') ? "battle:{$battleId}:monsters" : "battle:{$battleId}:characters_data";
        $raw = Redis::hget($hash, $inst);
        if (!$raw) return $entityCandidate;

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $entityCandidate;
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
                    $row->delete();
                }

                return [
                    'old_qty' => $qty,
                    'new_qty' => $newQty,
                    'deleted' => $newQty <= 0,
                ];
            });

            // 2) Redis: decrementa tanto a key da batalha quanto a preparatória (se fornecida)
            $battleKey = "battle:{$battleId}:character:{$playerInstanceId}:consumables";
            $keys = [$battleKey];
            if ($prepRedisKey) $keys[] = $prepRedisKey;

            $redisResult = $this->consumeItemRedis($keys, $consumableItemId, $amount);

            // 3) Verificação / reconciliation
            foreach ($redisResult as $idx => $r) {
                if (!isset($r['found']) || $r['found'] === false) {
                    Log::warning("[ItemService::consumeItem] Redis update issue for key {$keys[$idx]}", [
                        'payload' => $r,
                        'consumableItemId' => $consumableItemId,
                        'battle' => $battleId,
                        'playerInstance' => $playerInstanceId,
                    ]);
                    // opcional: re-sync do Redis a partir do DB
                }
            }

            // 4) Opcional: Broadcast para player/battle
            // BattleBroadcaster::broadcastToBattle(...);

            return [
                'db' => $dbResult,
                'redis' => $redisResult,
            ];
        } finally {
            try {
                Redis::del($lockKey);
            } catch (\Throwable $_) {
            }
        }
    }


    public function consumeItemRedis(array $keys, int|string $itemId, int $amount = 1): array
    {
        $itemIdStr = (string)$itemId;
        $results = [];

        foreach ($keys as $k => $key) {
            $hlen = (int)(Redis::hlen($key) ?: 0);
            if ($hlen > 0) {
                $hash = Redis::hgetall($key);
                $updated = false;
                $newQty = null;

                foreach ($hash as $field => $jsonVal) {
                    if ($field === $itemIdStr) {
                        $itm = json_decode($jsonVal, true);
                        if (!is_array($itm)) {
                            $results[$k] = ['found' => false, 'message' => 'invalid_json_in_field', 'field' => $field];
                            continue 2;
                        }

                        $qty = (int)($itm['quantity'] ?? 0);
                        $after = $qty - $amount;
                        if ($after > 0) {
                            $itm['quantity'] = $after;
                            Redis::hset($key, $field, json_encode($itm));
                            $newQty = $after;
                        } else {
                            Redis::hdel($key, $field);
                            $newQty = 0;
                        }
                        $updated = true;
                        break;
                    } else {
                        $itm = json_decode($jsonVal, true);
                        if (is_array($itm) && isset($itm['id']) && (string)$itm['id'] === $itemIdStr) {
                            $qty = (int)($itm['quantity'] ?? 0);
                            $after = $qty - $amount;
                            if ($after > 0) {
                                $itm['quantity'] = $after;
                                Redis::hset($key, $field, json_encode($itm));
                                $newQty = $after;
                            } else {
                                Redis::hdel($key, $field);
                                $newQty = 0;
                            }
                            $updated = true;
                            break;
                        }
                    }
                }

                if ($updated) {
                    if ((int)(Redis::hlen($key) ?: 0) === 0) Redis::del($key);
                    $results[$k] = ['found' => true, 'updated' => true, 'new_quantity' => $newQty];
                } else {
                    $results[$k] = ['found' => false, 'message' => 'item_not_found'];
                }
            } else {
                $raw = Redis::get($key);
                if (!$raw) {
                    $results[$k] = ['found' => false, 'message' => 'key_missing'];
                    continue;
                }

                $arr = json_decode($raw, true);
                if (!is_array($arr)) {
                    $results[$k] = ['found' => false, 'message' => 'not_array_or_invalid_json'];
                    continue;
                }

                $updated = false;
                $newQty = null;
                foreach ($arr as $i => $itm) {
                    if (isset($itm['id']) && (string)$itm['id'] === $itemIdStr) {
                        $qty = (int)($itm['quantity'] ?? 0);
                        $after = $qty - $amount;
                        if ($after > 0) {
                            $itm['quantity'] = $after;
                            $arr[$i] = $itm;
                            $newQty = $after;
                        } else {
                            array_splice($arr, $i, 1);
                            $newQty = 0;
                        }
                        $updated = true;
                        break;
                    }
                }

                if ($updated) {
                    if (count($arr) === 0) {
                        Redis::del($key);
                    } else {
                        Redis::set($key, json_encode($arr));
                    }
                    $results[$k] = ['found' => true, 'updated' => true, 'new_quantity' => $newQty];
                } else {
                    $results[$k] = ['found' => false, 'message' => 'item_not_found'];
                }
            }
        }

        return $results;
    }
}
