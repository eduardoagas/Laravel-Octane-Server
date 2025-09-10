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
        $characterId = isset($session['character_id']) ? (int)$session['character_id'] : null; // DB id

        if (!$battleId || !$characterId) {
            Log::warning("[SoulChangeInExecutionHandler] battleId or characterId missing in session", compact('battleId', 'characterId'));
            return;
        }

        // 1) Mapear characterId (DB) -> instanceId (chave usada na batalha)
        $playerInstanceId = null;
        $playersHash = Redis::hgetall("battle:$battleId:characters_data");
        foreach ($playersHash as $instanceKey => $json) {
            $decoded = @json_decode($json, true);
            if (is_array($decoded) && isset($decoded['id']) && (int)$decoded['id'] === $characterId) {
                $playerInstanceId = (string)$instanceKey;
                break;
            }
        }

        Log::info("[SoulChangeInExecutionHandler] Mapped characterId {$characterId} -> instanceId {$playerInstanceId} (battle {$battleId})");


        if (!$battleId || !$characterId) return;

        $pendingCacheKey = "battle:$battleId:pending_soul_change_cache:$playerInstanceId";
        $actionJson = Redis::get($pendingCacheKey);

        if (!$actionJson) {
            Log::warning("SoulChangeInExecution recebido sem ação cacheada", [
                'battleId' => $battleId,
                'playerInstanceId' => $playerInstanceId
            ]);
            return;
        }

        // Empilha na key de pending_soul_changes
        $pendingChangesKey = "battle:$battleId:pending_soul_changes";
        Redis::hset($pendingChangesKey, (string)$playerInstanceId, $actionJson);

        // Marca que está em execução (para bloquear novas tentativas)
        $executionKey = "battle:$battleId:soul_change_in_execution:$playerInstanceId";
        Redis::setex($executionKey, self::SOUL_CHANGE_LOCK_TTL, time());

        // Remove cache temporário
        Redis::del($pendingCacheKey);

        Log::info("SoulChangeInExecution: ação movida para pending_soul_changes", [
            'battleId' => $battleId,
            'playerInstanceId' => $playerInstanceId,
            'action' => json_decode($actionJson, true)
        ]);
    }
}
