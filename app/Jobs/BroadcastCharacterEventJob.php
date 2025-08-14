<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use App\Events\CharacterEvent;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class BroadcastCharacterEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $characterId;
    protected array $payload;
    protected string $eventName;

    public function __construct(string $characterId, array $payload, string $eventName = 'character_update')
    {
        $this->characterId = $characterId;
        $this->payload = $payload;
        $this->eventName = $eventName;
    }

    public function handle(): void
    {
        try {
            broadcast(new CharacterEvent($this->characterId, $this->payload, $this->eventName));
            /*broadcast(json_encode([
                'event' => $this->eventName,
                'data' => $this->payload, // Objeto direto
                'channel' => "character.{$this->characterId}"
            ]));*/
            Log::info("[BroadcastCharacterEventJob] Published for character." . $this->characterId);
        } catch (\Throwable $e) {
            Log::error("[BroadcastCharacterEventJob] Error publishing to character {$this->characterId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            // rethrow se quiser que a fila reprocese
            throw $e;
        }
    }
}
