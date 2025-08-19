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
        $skillIndex = $data['skill_id'] ?? null; // agora é índice do array
        $targetType = $data['target_type'] ?? null; // 'enemy' ou 'player'
        $targetId = $data['target_id'] ?? null;

        // Recupera sessão
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = $session['character_id'] ?? null;

        if (!$battleId || !$characterId || $skillIndex === null) {
            $connection->send(json_encode([
                'error' => 'Dados inválidos: battle_id, character_id ou skill_index ausentes'
            ]));
            return;
        }

        // Checa se existe skill em execução
        $executionKey = "battle:$battleId:skill_in_execution";
        if (Redis::exists($executionKey . ":$characterId")) {
            Log::info("[CanUseSkillHandler] Já há skill em execução");
            return;
        }

        // 1️⃣ Busca skills do Redis
        $skillsRaw = Redis::get("battle:$battleId:character:{$characterId}:skills");
        if (!$skillsRaw) {
            $connection->send(json_encode([
                'error' => 'Skills não carregadas para o personagem nesta batalha'
            ]));
            return;
        }
        $skillsArray = json_decode($skillsRaw, true);

        if (!isset($skillsArray[$skillIndex])) {
            $connection->send(json_encode([
                'error' => "Skill com índice $skillIndex não encontrada"
            ]));
            return;
        }

        $skill = $skillsArray[$skillIndex];

        // Checa stamina
        $stamina = StaminaService::getCurrentStamina($battleId, $characterId);
        $cost = $skill['stamina_cost'] ?? 0;

        $canUse = $stamina >= $cost;

        // Cache temporário da ação se possível
        if ($canUse) {
            $pendingCacheKey = "battle:$battleId:pending_actions_cache:$characterId";
            $actionPayload = [
                'caster_id' => $characterId,
                'caster_type' => 'character',
                'skill_id' => $skill['id'], // envia o id real do skill do banco
                'target_type' => $targetType,
                'target_id' => $targetId,
                'timestamp' => time(),
            ];
            Redis::set($pendingCacheKey, json_encode($actionPayload));
        } else {
            Log::channel("battle_debug")->info("SEM STAMINA para skill {$skill['name']}");
        }

        $connection->send(json_encode([
            'event' => 'skillQueued',
            'data' => [
                'skillId' => $skill['id'] ?? null,
            ]
        ]));
    }
}
