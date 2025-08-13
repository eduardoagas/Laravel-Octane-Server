<?php

namespace App\Services\Battle;

use App\Events\BattleEvent;
use Illuminate\Support\Facades\Log;
use App\Jobs\BroadcastBattleEventJob;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Services\UnityConnectionRegistry;

class BattleBroadcaster
{


    /**
     * Broadcast para todos os jogadores de uma batalha via Octane Job
     */
    public static function broadcastToBattle(string $battleId, array $payload, string $eventName = 'updateYourself'): void
    {
        Log::info("[BattleBroadcaster] Dispatching broadcast job to battle {$battleId} with event {$eventName}", [$payload]);

        // Cria e dispara job assíncrono
        $job = new BroadcastBattleEventJob($battleId, $payload, $eventName);

        // No Octane, podemos rodar imediatamente sem fila
        dispatch_sync($job); // ou dispatch($job) se quiser usar fila
    }
    /*public static function broadcastToBattle(string $battleId, array $payload, string $eventName = 'battle_event'): void
    {
        Log::info("BROADCASTING to battle the payload", [$payload]);
        broadcast(new BattleEvent($battleId, $payload, $eventName));
    }*?

    /* public static function broadcastToBattleNotWorking(string $battleId, array $payload, string $eventName = 'battle_event'): void
    {
        /* $application = [
            'id' => config('reverb.apps.0.id'),
            'key' => config('reverb.apps.0.key'),
            'secret' => config('reverb.apps.0.secret'),
        ];

        $eventData = [
            'event' => $eventName,
            'data' => $payload,  // Seu payload real (não precisa de json_encode aqui)
            'channels' => ["battle.{$battleId}"]
        ];

        // 1. Construir a string para assinatura HMAC (ordem específica!)
        $signatureContent = implode("\n", [
            $application['id'],
            $eventData['event'],
            json_encode($eventData['data']),
            implode(',', $eventData['channels'])
        ]);

        // 2. Calcular HMAC-SHA256 usando o segredo
        $signature = hash_hmac('sha256', $signatureContent, $application['secret']);

        $finalPayload = [
            'auth' => [
                'key' => $application['key'],
                'signature' => $signature,
                'timestamp' => time(), // Timestamp atual é obrigatório
            ],
            'application' => $application['id'], // APENAS O ID como string!
            'event' => $eventData['event'],
            'data' => $eventData['data'],
            'channels' => $eventData['channels']
        ];

        // 4. Publicar como string JSON
        Redis::publish('reverb', json_encode($finalPayload));
        Log::info("BROADCASTING VIA REDIS to battle the payload", [$payload]);
        //AQUI PRA BAIXO OUTRA COISA
        Redis::publish("redis", json_encode([
            'event' => 'updateYourself',
            'data' => json_encode($payload),
            'channel' => "battle.{$battleId}"
        ]));
    }*/

    /* public static function broadcastToBattle(string $battleId, array $payload, string $eventName = 'battle_event'): void
    {
        Log::info("BROADCASTING VIA REDIS to battle the payload", [$payload]);
        Redis::publish("reverb", new BattleEvent($battleId, $payload, $eventName));
    }*/
}
