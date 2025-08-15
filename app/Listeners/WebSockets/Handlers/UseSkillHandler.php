<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Exceptions\InsufficientStaminaException;
use App\Exceptions\SkillCooldownException;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class UseSkillHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $data = $payload['data'] ?? [];
        $skillId = $data['skill_id'] ?? null;
        $targetType = $data['target_type'] ?? null; // 'enemy' ou 'player'
        $targetId = $data['target_id'] ?? null;

        // Recupera sessão do jogador
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = $session['character_id'] ?? null;

        if (!$battleId || !$characterId || $skillId === null) {
            $connection->send(json_encode([
                'error' => 'Dados inválidos: battle_id, character_id ou skill_id ausentes'
            ]));
            return;
        }

        // Cria payload da ação
        $actionPayload = [
            'character_id' => $characterId,
            'skill_id' => (int) $skillId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'timestamp' => time(),
        ];

        $pendingKey = "battle:$battleId:pending_actions";

        // Substitui a ação anterior do mesmo jogador
        Redis::hset($pendingKey, $characterId, json_encode($actionPayload));

        Log::info("Action queued/replaced for battle $battleId, character $characterId: ", $actionPayload);

        // Confirma para o jogador que ação foi recebida
        $connection->send(json_encode([
            'event' => 'skillQueued',
            'channel' => "character.$characterId",
            'data' => [
                'message' => 'Skill action received and queued for processing'
            ]
        ]));
    }
}
