<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

/**
 * Payload esperado (evento "Ack"):
 * [
 *   'data' => [
 *     'ackId' => string // ex: "buff_remove:character:123:poison"
 *   ]
 * ]
 */
class BattleAckHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {

        Log::channel('battle_debug')->info('[BATTLE ACK HANDLER] payload recebido', $payload);
        $ackId = $payload['data']['ackId'] ?? null;
        Log::channel('battle_debug')->info("[BATTLE ACK HANDLER]" . $ackId);

        // Recupera sessão
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = $session['character_id'] ?? null;

        if (!$battleId || !$characterId) {
            //$connection->send(json_encode(['error' => 'Sessão inválida ou incompleta']));
            Log::warning("[AckHandler] Sessão inválida", compact('battleId', 'characterId'));
            return;
        }

        $ackKey = "battle:$battleId:acks";

        // Remove do Redis
        $removed = Redis::hdel($ackKey, $ackId);

        if ($removed) {
            Log::info("[AckHandler] ACK confirmado e removido", [
                'battle' => $battleId,
                'character_id' => $characterId,
                'ackId' => $ackId,
            ]);
        } else {
            Log::warning("[AckHandler] ACK não encontrado no Redis", [
                'battle' => $battleId,
                'character_id' => $characterId,
                'ackId' => $ackId,
            ]);
        }
    }
}
