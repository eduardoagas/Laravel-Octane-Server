<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class SkillInExecutionHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {

        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = $session['character_id'] ?? null;

        if (!$battleId || !$characterId) return;

        $pendingCacheKey = "battle:$battleId:pending_actions_cache:$characterId";
        $actionJson = Redis::get($pendingCacheKey);
        if (!$actionJson) {
            Log::warning("SkillInExecution recebido sem ação cacheada", [
                'battleId' => $battleId,
                'characterId' => $characterId
            ]);
            return;
        }

        // Empilha na key de pending_actions
        $pendingActionsKey = "battle:$battleId:pending_actions";
        Redis::hset($pendingActionsKey, $characterId, $actionJson);

        // Marca que está em execução (para bloquear CanUseSkill)
        $executionKey = "battle:$battleId:skill_in_execution:$characterId";
        Redis::set($executionKey, time());

        // Remove cache temporário
        Redis::del($pendingCacheKey);

        Log::info("SkillInExecution: ação movida para pending_actions", [
            'battleId' => $battleId,
            'characterId' => $characterId,
            'action' => json_decode($actionJson, true)
        ]);
    }
}
