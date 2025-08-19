<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

/**
 * Payload esperado (evento "CanChangeSoul"):
 * [
 *   'data' => [
 *     'slot_index' => int // índice 0-based da soul no equipped_soul_grid
 *   ]
 * ]
 *
 * Resposta (se enfileirado):
 * {
 *   "event":"soulChangeQueued",
 *   "data": {
 *     "slot_index": int,
 *     "canChange": bool,
 *     "new_active_soul_id": int|null
 *   }
 * }
 */
class CanChangeSoulHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $data = $payload['data'] ?? [];
        $slotIndex = $data['slot_index'] ?? null;

        if ($slotIndex === null) {
            $connection->send(json_encode(['error' => 'Slot index não fornecido']));
            return;
        }

        // Recupera sessão
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = $session['character_id'] ?? null;

        if (!$battleId || !$characterId) {
            $connection->send(json_encode(['error' => 'Dados inválidos: battle_id ou character_id ausentes']));
            return;
        }

        // Bloqueio de execução (se já estiver trocando soul)
        $executionKey = "battle:$battleId:soul_change_in_execution:$characterId";
        if (Redis::exists($executionKey)) {
            Log::info("[CanChangeSoulHandler] Já há uma troca de soul em execução para $characterId");
            $connection->send(json_encode([
                'event' => 'soulChangeQueued',
                'data' => [
                    'slot_index' => (int)$slotIndex,
                    'canChange' => false,
                    'reason' => 'soul_change_in_execution'
                ]
            ]));
            return;
        }

        // Pega o soul grid equipado do Redis
        $gridRaw = Redis::get("battle:$battleId:character:{$characterId}:equipped_soul_grid");
        if (!$gridRaw) {
            $connection->send(json_encode(['error' => 'Nenhum SoulGrid equipado encontrado no Redis']));
            return;
        }

        $soulsArray = json_decode($gridRaw, true);
        if (!is_array($soulsArray) || !isset($soulsArray[$slotIndex])) {
            $connection->send(json_encode(['error' => 'Slot inválido']));
            return;
        }

        $newActiveSoul = $soulsArray[$slotIndex];

        // Monta payload da ação (cache temporário)
        $actionPayload = [
            'caster_id' => $characterId,
            'action_type' => 'soul_change',
            'slot_index' => (int)$slotIndex,
            'new_active_soul_id' => $newActiveSoul['id'] ?? null,
            'timestamp' => time(),
        ];

        $pendingCacheKey = "battle:$battleId:pending_soul_change_cache:$characterId";
        Redis::set($pendingCacheKey, json_encode($actionPayload, JSON_UNESCAPED_UNICODE));

        Log::info("CanChangeSoul: ação cacheada", [
            'battle' => $battleId,
            'character_id' => $characterId,
            'slot_index' => $slotIndex,
            'new_active_soul_id' => $newActiveSoul['id'] ?? null,
        ]);

        // Resposta ao cliente
        $connection->send(json_encode([
            'event' => 'soulChangeQueued',
            'data' => [
                'slot_index' => (int)$slotIndex,
                'canChange' => true,
                'new_active_soul_id' => $newActiveSoul['id'] ?? null,
            ]
        ]));
    }
}
