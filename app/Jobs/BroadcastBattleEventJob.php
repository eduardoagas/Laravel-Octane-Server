<?php

namespace App\Jobs;

use App\Events\BattleEvent;

class BroadcastBattleEventJob
{
    protected string $battleId;
    protected array $payload;
    protected string $eventName;

    public function __construct(string $battleId, array $payload, string $eventName = 'updateYourself')
    {
        $this->battleId = $battleId;
        $this->payload = $payload;
        $this->eventName = $eventName;
    }

    public function handle(): void
    {
        // Dispara o broadcast usando o driver configurado (Reverb)
        broadcast(new BattleEvent($this->battleId, $this->payload, $this->eventName));
    }
}
