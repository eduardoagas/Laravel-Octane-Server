<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CharacterEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $characterId;
    public array $payload;
    protected string $eventName;

    public function __construct($characterId, array $payload, string $eventName = 'updateYourself')
    {
        $this->characterId = (string) $characterId;
        $this->payload = $payload; // array, não json_encode
        $this->eventName = $eventName;
    }

    public function broadcastOn()
    {
        return new Channel("character.{$this->characterId}");
    }

    public function broadcastWith(): array
    {
        return $this->payload; // array puro
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }
}
