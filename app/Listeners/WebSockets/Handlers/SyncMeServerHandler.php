<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class SyncMeServerHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $characterId = $payload['data']['character_id'] ?? null;
        if (!$characterId) {
            $connection->send(json_encode([
                'event' => 'sync_failed',
                'message' => 'Missing character_id'
            ]));
            return;
        }


        // Busca dados do Character
        $session = Redis::hgetall("session:$token");
        $characterId = isset($session['character_id']) ? (int)$session['character_id'] : null; // DB id

        // --- Atualiza stats + soul grid via handler ---
        (new CharacterSyncHandler())->handle($characterId, $connection);

        // --- Atualiza battlepack via handler ---
        (new BattlePackUpdateHandler())->handle($characterId, $connection);

        // Monta payload completo
        $payloadToSend = [
            'event' => 'all_sync',
            'channel' => "character.{$characterId}",
            'data' => new \stdClass(), // JSON vazio válido
        ];

        $connection->send(json_encode($payloadToSend, JSON_UNESCAPED_UNICODE));
        Log::info("sync_me_server_response enviado para character_id {$characterId}");
    }
}
