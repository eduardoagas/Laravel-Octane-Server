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
        $skillIndex = $data['skill_id'] ?? null; // índice no array de skills do Redis
        $targetType = $data['target_type'] ?? null; // 'enemy' ou 'player'
        $targetId = $data['target_id'] ?? null;

        // Recupera sessão
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = isset($session['character_id']) ? (int)$session['character_id'] : null; // DB id

        if (!$battleId || !$characterId || $skillIndex === null) {
            $connection->send(json_encode([
                'error' => 'Dados inválidos: battle_id, character_id ou skill_index ausentes'
            ]));
            Log::warning("[CanUseSkillHandler] Dados inválidos", compact('battleId', 'characterId', 'skillIndex'));
            return;
        }

        // Mapeia characterId -> instanceId (procura dentro do hash characters_data)
        $charactersHash = Redis::hgetall("battle:$battleId:characters_data");
        $playerInstanceId = null;
        foreach ($charactersHash as $instanceKey => $json) {
            $decoded = json_decode($json, true);
            if (isset($decoded['id']) && (int)$decoded['id'] === $characterId) {
                $playerInstanceId = (string)$instanceKey;
                break;
            }
        }

        if ($playerInstanceId === null) {
            $connection->send(json_encode(['error' => 'Character instance not found in this battle']));
            Log::error("[CanUseSkillHandler] Não encontrou instanceId para character_id no battle", [
                'battle' => $battleId,
                'character_id' => $characterId,
            ]);
            return;
        }

        Log::info("[CanUseSkillHandler] Mapeado characterId {$characterId} -> instanceId {$playerInstanceId} (battle {$battleId})");

        // Checa se existe skill em execução (usando instanceId agora)
        $executionKey = "battle:$battleId:skill_in_execution";
        if (Redis::exists($executionKey . ":{$playerInstanceId}")) {
            Log::info("[CanUseSkillHandler] Já há skill em execução para instance {$playerInstanceId}");
            return;
        }

        // 1️⃣ Busca skills do Redis usando instanceId
        $skillsRaw = Redis::get("battle:$battleId:character:{$playerInstanceId}:skills");
        if (!$skillsRaw) {
            $connection->send(json_encode([
                'error' => 'Skills não carregadas para o personagem nesta batalha'
            ]));
            Log::warning("[CanUseSkillHandler] Skills não carregadas no Redis para instance", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
            ]);
            return;
        }

        $skillsArray = json_decode($skillsRaw, true);
        if (!is_array($skillsArray)) {
            $connection->send(json_encode(['error' => 'Dados de skills inválidos']));
            Log::error("[CanUseSkillHandler] Skills JSON inválido para instance", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'raw' => $skillsRaw,
            ]);
            return;
        }

        if (!array_key_exists($skillIndex, $skillsArray)) {
            $connection->send(json_encode([
                'error' => "Skill com índice $skillIndex não encontrada"
            ]));
            Log::warning("[CanUseSkillHandler] Índice de skill inválido", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'skillIndex' => $skillIndex,
            ]);
            return;
        }

        $skill = $skillsArray[$skillIndex];

        // Checa stamina: usa instanceId e tipo 'character'
        try {
            $stamina = StaminaService::getCurrentStamina($battleId, (string)$playerInstanceId, 'character');
        } catch (\Throwable $e) {
            Log::error("[CanUseSkillHandler] Falha ao obter stamina", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'error' => $e->getMessage(),
            ]);
            $connection->send(json_encode(['error' => 'Erro ao verificar stamina']));
            return;
        }

        $cost = (float)($skill['stamina_cost'] ?? 0);
        $canUse = ($stamina >= $cost);

        // Cache temporário da ação se possível (usa instanceId)
        $pendingCacheKey = "battle:$battleId:pending_actions_cache:{$playerInstanceId}";
        if ($canUse) {
            $actionPayload = [
                'caster_id' => $playerInstanceId,           // instanceId
                'caster_type' => 'character',
                'skill_id' => $skill['id'],                // id real do skill no DB
                'target_type' => $targetType,
                'target_id' => $targetId,
                'timestamp' => time(),
            ];
            Redis::set($pendingCacheKey, json_encode($actionPayload, JSON_UNESCAPED_UNICODE));
            Log::info("[CanUseSkillHandler] Ação enfileirada", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'skill' => $skill['id'],
            ]);
        } else {
            Log::channel("battle_debug")->info("SEM STAMINA para skill {$skill['name']}", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'stamina' => $stamina,
                'cost' => $cost,
            ]);
        }

        $connection->send(json_encode([
            'event' => 'skillQueued',
            'data' => [
                'skillId' => $skill['id'] ?? null,
            ]
        ]));
    }
}
