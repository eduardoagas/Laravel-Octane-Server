<?php

namespace App\Listeners\WebSockets\Handlers;

use App\Models\Character;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class ConnectToServerHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        // —————————————
        // 1) Extrai e valida data
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            $connection->send(json_encode([
                'event'   => 'character_invalid',
                'message' => 'Missing data payload.',
            ]));
            return;
        }

        $characterId = $data['character_id'] ?? null;

        // —————————————
        // 2) Se enviou character_id, valida se pertence ao usuário
        if ($characterId) {
            $character = Character::where('id', $characterId)
                ->where('user_id', $userId)
                ->first();

            if (! $character) {
                $connection->send(json_encode([
                    'event'   => 'character_invalid',
                    'message' => 'Character not found or does not belong to user.',
                ]));
                return;
            }
        }
        // —————————————
        // 3) Se não enviou, cria ou recupera o primeiro
        else {
            Log::info("NOVO PERSONAGEM CRIADO");

            $character = Character::where('user_id', $userId)->first();
            if (! $character) {
                $character = Character::create([
                    'user_id'  => $userId,
                    'name'     => "Hero_{$userId}",
                    'maxhp'    => 100,
                    'hp'       => 100,
                    'level'    => 1,
                    'pattack'  => 10,
                    'mattack'  => 5,
                    'defense'  => 8,
                    'agility'  => 7,
                    'stamina'  => 12,
                ]);
            }
        }

        // —————————————
        // 4) Persiste no Redis
        $characterData = $character->toArray();

        Redis::hmset("session:$token", [
            'character_id' => $character->id
        ]);

        Redis::hmset("character_session:{$character->id}", [
            ...$characterData,
            'user_id' => $userId,
        ]);

        // —————————————
        // 5) Envia instrução para Unity assinar o canal do personagem
        $connection->send(json_encode([
            'event' => 'subscribeMe',
            'data' => [
                'channel' => "character.{$character->id}"
            ]
        ]));
    }
}
