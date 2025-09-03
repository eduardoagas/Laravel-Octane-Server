<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Character;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Laravel\Reverb\Contracts\Connection;

class BattlePackUpdateHandler
{
    /**
     * Atualiza apenas o battlepack do personagem
     */
    public function handle(int $characterId, Connection $connection): void
    {
        $battlePack = Character::find($characterId)?->battlePack?->slots ?? [];

        $slotsArray = [];
        foreach ($battlePack as $slot) {
            $slotsArray[] = [
                'slot_index' => $slot->slot_index,
                'consumable' => $slot->consumableItem?->consumable?->only([
                    'id',
                    'name',
                    'description',
                    'effect_type',
                    'effect_value'
                ]),
                'quantity' => $slot->consumableItem?->quantity ?? 0,
            ];
        }

        // Redis snapshot
        $instanceBattlePackKey = "character:{$characterId}:battlepack";
        Redis::set($instanceBattlePackKey, json_encode($slotsArray, JSON_UNESCAPED_UNICODE));

        // Payload
        $payload = [
            'event' => 'updateYourselfWorld',
            'channel' => "character.{$characterId}",
            'data' => [
                'battlepack' => $slotsArray,
            ],
        ];

        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE));
        Log::info("BattlePackUpdateHandler: payload enviado", ['character_id' => $characterId]);
    }
}
