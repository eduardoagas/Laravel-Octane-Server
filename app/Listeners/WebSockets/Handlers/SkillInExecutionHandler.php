<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class SkillInExecutionHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = isset($session['character_id']) ? (int)$session['character_id'] : null; // DB id

        if (!$battleId || !$characterId) {
            Log::warning("[SkillInExecutionHandler] battleId or characterId missing in session", compact('battleId', 'characterId'));
            return;
        }

        // 1) Mapear characterId (DB) -> instanceId (chave usada na batalha)
        $playerInstanceId = null;
        $playersHash = Redis::hgetall("battle:$battleId:characters_data");
        foreach ($playersHash as $instanceKey => $json) {
            $decoded = @json_decode($json, true);
            if (is_array($decoded) && isset($decoded['id']) && (int)$decoded['id'] === $characterId) {
                $playerInstanceId = (string)$instanceKey;
                break;
            }
        }

        // fallback: se não encontrou mapping, tenta usar characterId como instanceId (compatibilidade)
        if ($playerInstanceId === null) {
            if (array_key_exists((string)$characterId, $playersHash)) {
                $playerInstanceId = (string)$characterId;
                Log::warning("[SkillInExecutionHandler] Using DB characterId as instanceId for compatibility", [
                    'battle' => $battleId,
                    'characterId' => $characterId
                ]);
            } else {
                Log::error("[SkillInExecutionHandler] Could not find instanceId for character in battle", [
                    'battle' => $battleId,
                    'characterId' => $characterId
                ]);
                return;
            }
        }

        Log::info("[SkillInExecutionHandler] Mapped characterId {$characterId} -> instanceId {$playerInstanceId} (battle {$battleId})");

        // 2) Tenta carregar action cacheado - preferindo instanceId
        $pendingCacheKeyInstance = "battle:$battleId:pending_actions_cache:{$playerInstanceId}";
        $pendingCacheKeyChar = "battle:$battleId:pending_actions_cache:{$characterId}";

        $actionJson = Redis::get($pendingCacheKeyInstance);
        $usedCacheKey = $pendingCacheKeyInstance;
        if (!$actionJson) {
            $actionJson = Redis::get($pendingCacheKeyChar);
            $usedCacheKey = $pendingCacheKeyChar;
        }

        if (!$actionJson) {
            Log::warning("[SkillInExecutionHandler] SkillInExecution received but no cached action found", [
                'battleId' => $battleId,
                'characterId' => $characterId,
                'playerInstanceId' => $playerInstanceId,
                'triedKeys' => [$pendingCacheKeyInstance, $pendingCacheKeyChar],
            ]);
            return;
        }

        // 3) Insere em pending_actions usando instanceId como field
        $pendingActionsKey = "battle:$battleId:pending_actions";
        Redis::hset($pendingActionsKey, $playerInstanceId, $actionJson);

        // 4) Marca que está em execução (usando instanceId)
        $executionKey = "battle:$battleId:skill_in_execution:{$playerInstanceId}";
        Redis::set($executionKey, time());

        // 5) Remove cache temporário (remove ambas as keys por segurança/compat)
        try {
            Redis::del($pendingCacheKeyInstance);
            if ($pendingCacheKeyChar !== $pendingCacheKeyInstance) {
                Redis::del($pendingCacheKeyChar);
            }
        } catch (\Throwable $e) {
            Log::warning("[SkillInExecutionHandler] Falha ao remover pending cache keys", [
                'error' => $e->getMessage(),
                'keys' => [$pendingCacheKeyInstance, $pendingCacheKeyChar],
            ]);
        }

        $decodedAction = @json_decode($actionJson, true);
        Log::info("[SkillInExecutionHandler] Skill moved to pending_actions", [
            'battleId' => $battleId,
            'characterId' => $characterId,
            'instanceId' => $playerInstanceId,
            'usedCacheKey' => $usedCacheKey,
            'action' => $decodedAction,
        ]);
    }
}
