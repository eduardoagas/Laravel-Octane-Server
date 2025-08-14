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
    public static function broadcastToBattle(string $battleId, array $payload, string $eventName = 'updateYourself'): void
    {
        try {
            // Recupera os IDs dos personagens ativos na batalha (set)
            $characterIds = Redis::smembers("battle:{$battleId}:characters");

            if (empty($characterIds)) {
                Log::warning("[BattleBroadcaster] Nenhum personagem encontrado na batalha {$battleId}");
                return;
            }

            foreach ($characterIds as $charId) {
                // Opcional: buscar dados do character no hash se necessário
                $charJson = Redis::hget("battle:{$battleId}:characters", $charId); // se tiver hash
                if ($charJson) {
                    $charData = json_decode($charJson, true);
                } else {
                    $charData = ['id' => $charId]; // fallback se não houver hash
                }

                Log::info("[BattleBroadcaster] Enviando payload para character {$charId}", $payload);
                self::broadcastToCharacter($charId, $payload, $eventName);
            }

            Log::info("[BattleBroadcaster] Broadcast concluído para todos personagens da batalha {$battleId} com evento {$eventName}");
        } catch (\Throwable $e) {
            Log::error("[BattleBroadcaster] Erro ao enviar broadcast: {$e->getMessage()}", ['exception' => $e]);
        }
    }

    public static function broadcastToCharacter(
        string|int $characterId,
        array $payload,
        string $eventName = 'updateYourself'
    ): void {
        $channel = "character.{$characterId}";

        try {
            Broadcast::driver('reverb')->broadcast(
                [$channel],
                $eventName,
                $payload
            );

            Log::info("[ReverbDirect] Sent to {$channel}: {$eventName}", $payload);
        } catch (\Throwable $e) {
            Log::error("[ReverbDirect] Error ao enviar para {$channel}: " . $e->getMessage());
        }
    }
}
