<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class SubscribeConfirmedHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        Log::info(" [SUBSCRIBECONFIRMED] Rodando subscribe confirmed");
        $data = $payload['data'] ?? [];
        $channel = $data['channel'] ?? null;

        if (!$channel) {
            $connection->send(json_encode(['error' => 'Channel missing in subscribeConfirmed']));
            return;
        }

        // —————————————
        // Canal de personagem
        if (preg_match('/^character\.(\d+)$/', $channel, $matches)) {
            $characterId = (int) $matches[1];

            $characterData = Redis::hgetall("character_session:$characterId");
            if (!$characterData) {
                $connection->send(json_encode([
                    'event' => 'character_invalid',
                    'message' => "No character data found for ID $characterId"
                ]));
                return;
            }

            // Normaliza tipos básicos
            if (isset($characterData['id'])) {
                $characterData['id'] = (int) $characterData['id'];
            } else {
                $characterData['id'] = $characterId;
            }

            if (isset($characterData['user_id'])) {
                $characterData['user_id'] = (int) $characterData['user_id'];
            }

            // Normaliza o campo stats: pode ser JSON string, "Array", já-array, etc.
            $characterData['stats'] = $this->normalizeStatsValue($characterData['stats'] ?? null);
            $characterData = $this->filterCharacterData($characterData);
            //$normalizedStats = $this->normalizeStatsValue($stats);
            //$normalizedSoulsArray = $this->normalizeStatsValue($soulsArray);

            // Monta payload para enviar — stats já está como array/obj
            $out = [
                'event' => 'character_connected',
                'data'  => ['character' => $characterData],
            ];

            // Debug: confirme como está o payload antes de enviar
            Log::debug('[SUBSCRIBECONFIRMED] Outgoing payload', $out);

            $connection->send(json_encode($out, JSON_UNESCAPED_UNICODE));
            return;
        }

        // —————————————
        // Outros canais (se houver)
    }

    protected function filterCharacterData(array $characterData): array
{
    return [
        'id' => $characterData['id'] ?? null,
        'user_id' => $characterData['user_id'] ?? null,
        'stats' => [
            'hp' => $characterData['stats']['hp'] ?? null,
            'stamina' => $characterData['stats']['stamina'] ?? null,
            'agility' => $characterData['stats']['agility'] ?? null,
            // adicione aqui apenas os atributos que o Unity realmente utiliza
        ],
        // Campos que o Unity não usa podem ser omitidos ou deixados nulos
        'name' => $characterData['name'] ?? null,
        'created_at' => $characterData['created_at'] ?? null,
        'updated_at' => $characterData['updated_at'] ?? null,
    ];
}

    /**
     * Normaliza um valor de "stats":
     * - se for array -> retorna array
     * - se for string JSON -> decodifica
     * - se for "Array" (literal) -> retorna []
     * - otherwise -> []
     */
    protected function normalizeStatsValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        // Caso comum: quem gravou no Redis deixou a string JSON (ex: '{"hp":100,...}')
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Se alguém gravou literalmente "Array" (quando passou array direto para hset), trata como vazio
        if ($value === 'Array') {
            return [];
        }

        // Tentar uma segunda decodificação se for string com aspas escapadas (double-encoded)
        // ex: "\"{...}\"" -> remove extra aspas e tenta decodificar
        $trimmed = trim($value, "\"'");
        $decoded2 = json_decode($trimmed, true);
        if (is_array($decoded2)) {
            return $decoded2;
        }

        // fallback
        return [];
    }
}

