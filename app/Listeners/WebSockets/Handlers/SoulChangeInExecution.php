<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

/**
 * Handler que confirma a execução da troca de soul.
 * Evento recebido do cliente: "SoulChange" (ou outro gatilho)
 *
 * Fluxo:
 * - Lê cache temporário: battle:$battleId:pending_soul_change_cache:$characterId
 * - Move para hash de pending_soul_changes (battle:$battleId:pending_soul_changes)
 * - Marca execução: battle:$battleId:soul_change_in_execution:$characterId
 * - Remove cache temporário
 *
 * Nota: a lógica de aplicar efetivamente a troca (atualizar active_soul_id, skills, notificar jogadores)
 * ficará a cargo do battleManager que processa pending_soul_changes.
 */


class SoulChangeInExecutionHandler implements HandlesUnityEvent
{
    const SOUL_CHANGE_LOCK_TTL = 6;
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = $session['character_id'] ?? null;

        if (!$battleId || !$characterId) return;

        $pendingCacheKey = "battle:$battleId:pending_soul_change_cache:$characterId";
        $actionJson = Redis::get($pendingCacheKey);

        if (!$actionJson) {
            Log::warning("SoulChangeInExecution recebido sem ação cacheada", [
                'battleId' => $battleId,
                'characterId' => $characterId
            ]);
            return;
        }

        // Empilha na key de pending_soul_changes
        $pendingChangesKey = "battle:$battleId:pending_soul_changes";
        Redis::hset($pendingChangesKey, (string)$characterId, $actionJson);

        // Marca que está em execução (para bloquear novas tentativas)
        $executionKey = "battle:$battleId:soul_change_in_execution:$characterId";
        Redis::setex($executionKey, self::SOUL_CHANGE_LOCK_TTL, time());

        // Remove cache temporário
        Redis::del($pendingCacheKey);

        Log::info("SoulChangeInExecution: ação movida para pending_soul_changes", [
            'battleId' => $battleId,
            'characterId' => $characterId,
            'action' => json_decode($actionJson, true)
        ]);
    }
}
