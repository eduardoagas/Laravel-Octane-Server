<?php

namespace App\Services\Battle;

use App\Events\BattleEvent;
use App\Events\CharacterEvent;
use Illuminate\Support\Facades\Log;
use App\Jobs\BroadcastBattleEventJob;
use Illuminate\Support\Facades\Redis;
use App\Jobs\BroadcastCharacterEventJob;
use Laravel\Reverb\Contracts\Connection;
use App\Services\UnityConnectionRegistry;
use Illuminate\Support\Facades\Broadcast;

class BattleBroadcaster
{


    /*public static function broadcastToCharacter(string|int $characterId, array $payload, string $eventName = 'updateYourself'): void
    {
        Log::info("[BattleBroadcaster] Dispatching broadcast job to character {$characterId} with event {$eventName}", [$payload]);

        $job = new BroadcastCharacterEventJob((string)$characterId, $payload, $eventName);

        dispatch_sync($job);
    }*/

    public static function broadcastToBattle(string $battleId, array $payload, string $eventName = 'updateYourself'): void
    {
        try {
            // Busca todos os personagens da batalha no Redis
            $characters = Redis::hgetall("battle:{$battleId}:characters");

            if (empty($characters)) {
                Log::warning("[BattleBroadcaster] Nenhum personagem encontrado na batalha {$battleId}");
                return;
            }

            $uniqueCharacters = array_column(array_map(fn($json) => json_decode($json, true), $characters), null, 'id');

            // Agora $uniqueCharacters contém apenas um registro por characterId

            foreach ($uniqueCharacters as $charId => $charJson) {
                $charData = json_decode($charJson, true);
                if (!isset($charData['id'])) {
                    Log::warning("[BattleBroadcaster] Personagem inválido no Redis: {$charId}");
                    continue;
                }

                self::broadcastToCharacter($charData['id'], $payload, $eventName);
            }

            Log::info("[BattleBroadcaster] Broadcast para todos personagens da batalha {$battleId} com evento {$eventName}");
        } catch (\Throwable $e) {
            Log::error("[BattleBroadcaster] Erro ao enviar para batalha {$battleId}: {$e->getMessage()}", [
                'exception' => $e
            ]);
        }
    }

    /**
     * Broadcast para todos os jogadores de uma batalha via Octane Job
     */
    /* public static function broadcastToBattle(string $battleId, array $payload, string $eventName = 'updateYourself'): void
    {
        Log::info("[BattleBroadcaster] Dispatching broadcast job to battle {$battleId} with event {$eventName}", [$payload]);

        // Cria e dispara job assíncrono
        $job = new BroadcastBattleEventJob($battleId, $payload, $eventName);

        // No Octane, podemos rodar imediatamente sem fila
        dispatch_sync($job); // ou dispatch($job) se quiser usar fila
    }*/
    public static function broadcastToCharacterA(string|int $characterId, array $payload, string $eventName = 'battle_event'): void
    {
        Log::info("BROADCASTING to battle the payload", [$payload]);
        broadcast(new CharacterEvent((string) $characterId, $payload, $eventName));
    }

    /*public static function broadcastToCharacter(
        string|int $characterId,
        array $payload,
        string $eventName = 'updateYourself'
    ): void {
        $channel = "character.{$characterId}";

        $message = json_encode([
            'event' => $eventName,
            'data' => $payload,
            'channel' => $channel
        ]);

        Redis::publish('reverb', $message);

        Log::info("[Redis] Published to {$channel}: {$eventName}", $payload);
    }*/

    /*public static function broadcastToCharacter(string $battleId, array $payload, string $eventName = 'battle_event'): void
    {
        $application = [
            'id' => config('reverb.apps.0.id'),
            'key' => config('reverb.apps.0.key'),
            'secret' => config('reverb.apps.0.secret'),
        ];

        $eventData = [
            'event' => $eventName,
            'data' => $payload,  // Seu payload real (não precisa de json_encode aqui)
            'channels' => ["character.{$battleId}"]
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
        Redis::publish('redis', json_encode($finalPayload));
        Log::info("BROADCASTING VIA REDIS to battle the payload", [$payload]);
        //AQUI PRA BAIXO OUTRA COISA
        /*Redis::publish("redis", json_encode([
            'event' => 'updateYourself',
            'data' => json_encode($payload),
            'channel' => "battle.{$battleId}"
        ]));
    }*/

    public static function broadcastToCharacter(
        string|int $characterId,
        array $payload,
        string $eventName = 'updateYourself'
    ): void {
        $channel = "character.{$characterId}";

        try {
            // Envio direto via Reverb
            Broadcast::driver('reverb')->broadcast(
                [$channel],
                $eventName,
                $payload
            );

            Log::info("[ReverbDirect] Sent to {$channel}: {$eventName}", $payload);
        } catch (\Throwable $e) {
            Log::error("[ReverbDirect] Error: " . $e->getMessage());
        }
    }

    /* public static function broadcastToBattle(string $battleId, array $payload, string $eventName = 'battle_event'): void
    {
        Log::info("BROADCASTING VIA REDIS to battle the payload", [$payload]);
        Redis::publish("reverb", new BattleEvent($battleId, $payload, $eventName));
    }*/

    public static function broadcastToCharacterR(string $battleId, array $payload, string $eventName = 'battle_event'): void
    {
        Log::info("BROADCASTING VIA REDIS to battle the payload", [$payload]);
        Redis::publish(
            "character.{$battleId}",
            json_encode($payload),
        );
    }
}
