<?php

namespace App\Services\Battle;

use App\Events\BattleEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Services\UnityConnectionRegistry;

class BattleBroadcaster
{
    /**
     * Envia uma mensagem para todos os usuários conectados à batalha.
     *
     * @param string $battleId
     * @param array $payload Dados que serão enviados no broadcast
     * @return void
     */
    public static function broadcastToBattle(string $battleId, array $payload): void
    {
        Log::info("BROADCASTING to battle the payload", [$payload]);
        broadcast(new BattleEvent($battleId, $payload))->toOthers();
    }
}
