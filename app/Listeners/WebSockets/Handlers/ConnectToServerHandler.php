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
                    'user_id' => $userId,
                    'name'    => "Hero_{$userId}",
                ]);

                $character->stats()->create([
                    'hp'            => 100,
                    'level'         => 1,
                    'strength'      => 10,
                    'intelligence'  => 5,
                    'defense_bonus' => 8,
                    'mdefense_bonus' => 8,
                    'dexterity'     => 7,
                    'stamina'       => 12,
                ]);
            }
        }

        // —————————————
        // 4) Persiste no Redis
        $characterData = $character->toArray();

        // Salva apenas o character_id na sessão
        Redis::hset("session:$token", [
            'character_id' => $character->id
        ]);

        // Salva todos os dados do character + stats em uma única chave
        Redis::hset("character_session:{$character->id}", [
            'id'         => $characterData['id'],
            'user_id'    => $userId,
            'name'       => $characterData['name'],
            'created_at' => $characterData['created_at'],
            'updated_at' => $characterData['updated_at'],
            'stats'      => json_encode($characterData['stats']), // stats como JSON
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
