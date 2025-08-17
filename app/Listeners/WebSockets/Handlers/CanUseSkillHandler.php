<?php


namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class CanUseSkillHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {

        $data = $payload['data'] ?? [];
        $skillId = $data['skill_id'] ?? null;
        $targetType = $data['target_type'] ?? null; // 'enemy' ou 'player'
        $targetId = $data['target_id'] ?? null;

        // Recupera sessão
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = $session['character_id'] ?? null;

        if (!$battleId || !$characterId || $skillId === null) {
            $connection->send(json_encode([
                'error' => 'Dados inválidos: battle_id, character_id ou skill_id ausentes'
            ]));
            return;
        }

        // Checa se existe skill em execução
        $executionKey = "battle:$battleId:skill_in_execution";
        if (Redis::exists($executionKey . ":$characterId")) {
            Log::info("[CanUseSkillHandler] Já há skill em execução");
            return;
        }

        // Checa stamina
        $stamina = StaminaService::getCurrentStamina($battleId, $characterId);
        $cost = SkillService::getSkillStaminaCost($skillId);

        $canUse = $stamina >= $cost;

        // Cache temporário da ação se possível
        if ($canUse) {
            $pendingCacheKey = "battle:$battleId:pending_actions_cache:$characterId";
            // Cria payload da ação
            $actionPayload = [
                'caster_id' => $characterId,
                'caster_type' => 'character',
                'skill_id' => (int) $skillId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'timestamp' => time(),
            ];
            Redis::set($pendingCacheKey, json_encode($actionPayload));
        } else {
            Log::channel("battle_debug")->info("SEM STAMINA");
        }


        $connection->send(json_encode([
            'event' => 'skillQueued',
            'data' => [
                'skillId' => (int) $skillId,
            ]
        ]));
    }
}
