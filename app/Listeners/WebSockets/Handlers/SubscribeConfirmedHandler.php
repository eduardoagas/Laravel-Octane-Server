<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class SubscribeConfirmedHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        Log::info(" [SUBSCRIBECONFIRMED] Rodando subscribe confirmed");
        $data = $payload['data'] ?? [];
        $channel = $data['channel'] ?? null;

        if (!$channel) {
            $connection->send(json_encode(['error' => 'Channel missing in subscribeConfirmed']));
            return;
        }

        // —————————————
        // Canal de personagem
        if (preg_match('/^character\.(\d+)$/', $channel, $matches)) {
            $characterId = $matches[1];

            $characterData = Redis::hgetall("character_session:$characterId");
            if (!$characterData) {
                $connection->send(json_encode([
                    'event' => 'character_invalid',
                    'message' => "No character data found for ID $characterId"
                ]));
                return;
            }

            // Responde para Unity que o personagem está conectado
            $connection->send(json_encode([
                'event' => 'character_connected',
                'data' => ['character' => $characterData]
            ]));

            return;
        }

        // —————————————
        // (Aqui ficaria a lógica para outros canais, como batalha, se necessário)
    }
}
