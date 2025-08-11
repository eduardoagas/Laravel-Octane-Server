<?php

namespace App\Services\Battle;

use App\Events\BattleEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Services\UnityConnectionRegistry;

class BattleBroadcaster
{
    public static function broadcastToBattle(string $battleId, array $payload, string $eventName = 'battle_event'): void
    {
        Log::info("BROADCASTING to battle the payload", [$payload]);
        broadcast(new BattleEvent($battleId, $payload, $eventName));
    }
}
