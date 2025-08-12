<?php

// app/WebSocket/Handlers/BattleWithMonsterHandler.php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Services\UnityConnectionRegistry;
use App\Services\Battle\BattleBroadcaster;
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

        // Extraia o battleId do canal (ex: "battle.battle_abc123" -> "battle_abc123")
        if (preg_match('/^battle\.(.+)$/', $channel, $matches)) {
            $battleId = $matches[1];
            // Registrar batalha como ativa agora que cliente está inscrito
            Redis::sadd('battles:active', $battleId);
            // Agora pode disparar o updateYourself para esse usuário/instância
            $characterId = $this->getCharacterIdByUserId($userId); // implemente conforme sua lógica

            $characterJson = Redis::hgetall("character_session:$characterId");

            $currentCharacterStaminaData = json_decode(
                Redis::hget("battle:$battleId:stamina_data", "character:$characterId"),
                true
            );

            BattleBroadcaster::broadcastToBattle($battleId, [
                'players' => [
                    [
                        'instanceId' => (string)$characterId,
                        'currentHp' => (int) ($characterJson['hp'] ?? 0),
                        'staminaData' => $currentCharacterStaminaData,
                        'nstatus' => 'none',
                        'pstatus' => 'none'
                    ]
                ],
            ], 'updateYourself');
        }
    }

    protected function getCharacterIdByUserId(int $userId): ?string
    {
        // 1. Pega o token da sessão (string)
        $token = Redis::get("user_token:$userId");

        if (!$token) {
            Log::warning("Token não encontrado para userId $userId");
            return null;
        }

        // 2. Busca a hash da sessão
        $sessionData = Redis::hgetall("session:$token");

        if (empty($sessionData)) {
            Log::warning("Dados de sessão não encontrados para token $token");
            return null;
        }

        // 3. Retorna o character_id da sessão
        return $sessionData['character_id'] ?? null;
    }
}
