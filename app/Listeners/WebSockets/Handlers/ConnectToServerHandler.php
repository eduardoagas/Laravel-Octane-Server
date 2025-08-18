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
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            $connection->send(json_encode([
                'event'   => 'character_invalid',
                'message' => 'Missing data payload.',
            ]));
            return;
        }

        $characterId = $data['character_id'] ?? null;

        if ($characterId) {
            $character = Character::where('id', $characterId)
                ->where('user_id', $userId)
                ->first();

            if (!$character) {
                $connection->send(json_encode([
                    'event'   => 'character_invalid',
                    'message' => 'Character not found or does not belong to user.',
                ]));
                return;
            }
        } else {
            Log::info("NOVO PERSONAGEM CRIADO");
            $character = Character::where('user_id', $userId)->first();

            if (!$character) {
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

        // garante relação carregada
        $character->load('stats');

        // ===== preparar stats =====
        $statsArray = $character->stats ? $character->stats->toArray() : [];
        $statsJson  = json_encode($statsArray, JSON_UNESCAPED_UNICODE);

        if ($statsJson === false) {
            Log::error('Falha ao serializar stats', ['character_id' => $character->id, 'err' => json_last_error_msg()]);
            $statsJson  = json_encode([]);
            $statsArray = [];
        }

        // ===== salvar no Redis (stats como string JSON) =====
        Redis::hset("session:$token", 'character_id', (string) $character->id);
        Redis::hmset("character_session:{$character->id}", [
            'id'         => (string) $character->id,
            'user_id'    => (string) $userId,
            'name'       => (string) $character->name,
            'created_at' => $character->created_at->toDateTimeString(),
            'updated_at' => $character->updated_at->toDateTimeString(),
            'stats'      => $statsJson, // importantíssimo: JSON string no Redis
        ]);

        // ===== montar payload para Unity =====
        $payloadToSend = [
            'event' => 'subscribeMe',
            'data'  => [
                'channel' => "character.{$character->id}",
                'character' => [
                    'id'    => $character->id,
                    'name'  => $character->name,
                    'stats' => $statsArray, // ENSINA: enviar como array/objeto aqui
                ],
            ],
        ];

        // debug útil: loga o payload *antes* do json_encode final para confirmar tipo
        Log::debug('WS OUT (subscribeMe)', $payloadToSend);

        $connection->send(json_encode($payloadToSend, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Normaliza um valor de "stats" que você pode ter vindo do Redis/Outro handler:
     * - se for string -> tenta json_decode
     * - se for "Array" (literal) -> retorna []
     * - se for array/object -> retorna array
     */
    protected function normalizeStatsValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (is_string($value)) {
            // strings "Array" aparecem quando alguém gravou diretamente um array no hset
            if ($value === 'Array') {
                return [];
            }
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            // fallback
            return [];
        }

        return [];
    }

    /**
     * Exemplo de leitura do Redis usando normalização (use quando montar payloads a partir do Redis)
     */
    protected function buildCharacterPayloadFromRedis(int $characterId): array
    {
        $hash = Redis::hgetall("character_session:{$characterId}");
        if (empty($hash)) {
            return [];
        }
        $stats = $this->normalizeStatsValue($hash['stats'] ?? null);

        return [
            'id'   => isset($hash['id']) ? (int)$hash['id'] : $characterId,
            'name' => $hash['name'] ?? null,
            'stats' => $stats,
        ];
    }
}
