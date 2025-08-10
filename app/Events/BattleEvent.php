<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BattleEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $battleId;
    public $payload;

    public function __construct($battleId, $payload)
    {
        $this->battleId = $battleId;
        $this->payload = $payload;
    }

    public function broadcastOn()
    {
        return new Channel("battle.{$this->battleId}");
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
