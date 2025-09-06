<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

class CanUseItemHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $data = $payload['data'] ?? [];
        $itemIndex = $data['item_id'] ?? null; // índice no array de item do Redis
        $targetType = $data['target_type'] ?? null; // 'enemy' ou 'player'
        $targetId = $data['target_id'] ?? null;

        // Recupera sessão
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = isset($session['character_id']) ? (int)$session['character_id'] : null; // DB id

        if (!$battleId || !$characterId || $itemIndex === null) {
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

        // Checa se existe item em execução (usando instanceId agora)
        $executionKey = "battle:$battleId:skill_in_execution";
        if (Redis::exists($executionKey . ":{$playerInstanceId}")) {
            Log::info("[CanUseSkillHandler] Já há item em execução para instance {$playerInstanceId}");
            return;
        }

        // 1️⃣ Busca items do Redis usando instanceId
        $itemsRaw = Redis::get("battle:$battleId:character:{$playerInstanceId}:consumables");
        if (!$itemsRaw) {
            $connection->send(json_encode([
                'error' => 'Itens não carregadas para o personagem nesta batalha'
            ]));
            Log::warning("[CanUseSkillHandler] items não carregadas no Redis para instance", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
            ]);
            return;
        }

        $itemsArray = json_decode($itemsRaw, true);
        if (!is_array($itemsArray)) {
            $connection->send(json_encode(['error' => 'Dados de skills inválidos']));
            Log::error("[CanUseItemHandler] items JSON inválido para instance", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'raw' => $itemsRaw,
            ]);
            return;
        }

        if (!array_key_exists($itemIndex, $itemsArray)) {
            $connection->send(json_encode([
                'error' => "Item com índice $itemIndex não encontrada"
            ]));
            Log::warning("[CanUseItemHandler] Índice de item inválido", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'itemIndex' => $itemIndex,
            ]);
            return;
        }

        $item = $itemsArray[$itemIndex];

        // Checa stamina: usa instanceId e tipo 'character'
        /*try {
            $stamina = StaminaService::getCurrentStamina($battleId, (string)$playerInstanceId, 'character');
        } catch (\Throwable $e) {
            Log::error("[CanUseItemHandler] Falha ao obter stamina", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'error' => $e->getMessage(),
            ]);
            $connection->send(json_encode(['error' => 'Erro ao verificar stamina']));
            return;
        }

        $cost = (float)($skill['stamina_cost'] ?? 0);
        $canUse = ($stamina >= $cost);*/
        $canUse = true; //mudar checks

        // Cache temporário da ação se possível (usa instanceId)
        $pendingCacheKey = "battle:$battleId:pending_actions_cache:{$playerInstanceId}";
        if ($canUse) {
            $actionPayload = [
                'caster_id' => $playerInstanceId,           // instanceId
                'caster_type' => 'character',
                'item_id' => $item['id'],                // id real do skill no DB
                'target_type' => $targetType,
                'target_id' => $targetId,
                'timestamp' => time(),
            ];
            Redis::set($pendingCacheKey, json_encode($actionPayload, JSON_UNESCAPED_UNICODE));
            Log::info("[CanUseItemHandler] Ação enfileirada", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'item' => $item['id'],
            ]);
        } else {
            Log::channel("battle_debug")->info("Algo deu errado para {$item['name']}", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                //'stamina' => $stamina,
                //'cost' => $cost,
            ]);
        }

        $connection->send(json_encode([
            'event' => 'itemQueued',
            'data' => [
                'itemId' => $skill['id'] ?? null,
            ]
        ]));
    }
}
