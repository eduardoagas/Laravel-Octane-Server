<?php

namespace App\Listeners\WebSockets\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Contracts\Connection;
use App\Listeners\WebSockets\Contracts\HandlesUnityEvent;

/**
 * Payload esperado (evento "CanChangeSoul"):
 * [
 *   'data' => [
 *     'slotIndex' => int // índice 0-based da soul no equipped_soul_grid
 *   ]
 * ]
 *
 * Resposta (se enfileirado):
 * {
 *   "event":"soulChangeQueued",
 *   "data": {
 *     "slot_index": int,
 *     "canChange": bool,
 *     "new_active_soul_id": int|null
 *   }
 * }
 */
class CanChangeSoulHandler implements HandlesUnityEvent
{
    public function handle(array $payload, int $userId, string $token, Connection $connection): void
    {
        $data = $payload['data'] ?? [];
        $slotIndex = isset($data['slotIndex']) ? (int)$data['slotIndex'] : null;

        if ($slotIndex === null) {
            $connection->send(json_encode(['error' => 'Slot index não fornecido']));
            return;
        }

        // Recupera sessão
        $session = Redis::hgetall("session:$token");
        $battleId = $session['battle_instance_id'] ?? null;
        $characterId = isset($session['character_id']) ? (int)$session['character_id'] : null; // DB id

        if (!$battleId || !$characterId) {
            $connection->send(json_encode(['error' => 'Dados inválidos: battle_id ou character_id ausentes']));
            Log::warning("[CanChangeSoulHandler] Dados inválidos", compact('battleId', 'characterId'));
            return;
        }

        // 1) Mapear characterId (DB) -> instanceId (chave usada na batalha)
        $playerInstanceId = null;
        $playersHash = Redis::hgetall("battle:$battleId:characters_data");
        foreach ($playersHash as $instanceKey => $json) {
            $decoded = json_decode($json, true);
            if (isset($decoded['id']) && (int)$decoded['id'] === $characterId) {
                $playerInstanceId = (string)$instanceKey;
                break;
            }
        }

        if ($playerInstanceId === null) {
            // fallback: tentar usar characterId como instanceId (compatibilidade)
            if (array_key_exists((string)$characterId, $playersHash)) {
                $playerInstanceId = (string)$characterId;
                Log::warning("[CanChangeSoulHandler] Usando characterId como instanceId por compatibilidade", [
                    'battle' => $battleId,
                    'characterId' => $characterId
                ]);
            } else {
                $connection->send(json_encode(['error' => 'Character instance not found in this battle']));
                Log::error("[CanChangeSoulHandler] Não encontrou instanceId para character_id no battle", [
                    'battle' => $battleId,
                    'character_id' => $characterId,
                ]);
                return;
            }
        }

        Log::info("[CanChangeSoulHandler] Mapeado characterId {$characterId} -> instanceId {$playerInstanceId} (battle {$battleId})");

        // 2) Verifica se já há troca em execução (lock) usando instanceId
        $executionKey = "battle:$battleId:soul_change_in_execution:{$playerInstanceId}";
        if (Redis::exists($executionKey)) {
            Log::info("[CanChangeSoulHandler] Já há uma troca de soul em execução para instance {$playerInstanceId}");
            $connection->send(json_encode([
                'event' => 'soulChangeQueued',
                'data' => [
                    'slot_index' => (int)$slotIndex,
                    'canChange' => false,
                    'reason' => 'soul_change_in_execution'
                ]
            ]));
            return;
        }

        // 3) Pega o soul grid equipado do Redis (tenta instanceId primeiro, depois characterId por compat)
        $gridKeyInstance = "battle:$battleId:character:{$playerInstanceId}:equipped_soul_grid";
        $gridRaw = Redis::get($gridKeyInstance);

        if (!$gridRaw) {
            // tentativa compatibilidade (se alguma parte ainda gravou usando DB characterId)
            $gridKeyChar = "battle:$battleId:character:{$characterId}:equipped_soul_grid";
            $gridRaw = Redis::get($gridKeyChar);
            if ($gridRaw) {
                Log::warning("[CanChangeSoulHandler] equipped_soul_grid encontrado pela key characterId (compat)", [
                    'battle' => $battleId,
                    'characterId' => $characterId,
                    'instanceId' => $playerInstanceId,
                ]);
            }
        }

        if (!$gridRaw) {
            $connection->send(json_encode(['error' => 'Nenhum SoulGrid equipado encontrado no Redis']));
            Log::warning("[CanChangeSoulHandler] equipped_soul_grid não encontrado", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'characterId' => $characterId,
                'triedKeys' => [$gridKeyInstance ?? null, $gridKeyChar ?? null],
            ]);
            return;
        }

        $soulsArray = json_decode($gridRaw, true);
        if (!is_array($soulsArray) || !array_key_exists($slotIndex, $soulsArray)) {
            $connection->send(json_encode(['error' => 'Slot inválido']));
            Log::warning("[CanChangeSoulHandler] Slot inválido no equipped_soul_grid", [
                'battle' => $battleId,
                'instanceId' => $playerInstanceId,
                'slotIndex' => $slotIndex,
            ]);
            return;
        }

        $newActiveSoul = $soulsArray[$slotIndex];

        // 4) Enfileira a ação usando instanceId no hash que o worker/processPendingSoulChanges espera
        $pendingHash = "battle:$battleId:pending_soul_changes";
        $actionPayload = [
            'caster_id' => $playerInstanceId,           // instanceId
            'action_type' => 'soul_change',
            'slot_index' => (int)$slotIndex,
            'new_active_soul_id' => $newActiveSoul['id'] ?? null,
            'timestamp' => time(),
        ];

        Redis::hset($pendingHash, $playerInstanceId, json_encode($actionPayload, JSON_UNESCAPED_UNICODE));

        Log::info("[CanChangeSoulHandler] Ação de troca de soul enfileirada", [
            'battle' => $battleId,
            'instanceId' => $playerInstanceId,
            'slot_index' => $slotIndex,
            'new_active_soul_id' => $newActiveSoul['id'] ?? null,
        ]);

        // 5) Resposta ao cliente
        $connection->send(json_encode([
            'event' => 'soulChangeQueued',
            'data' => [
                'slot_index' => (int)$slotIndex,
                'canChange' => true,
                'new_active_soul_id' => $newActiveSoul['id'] ?? null,
            ]
        ]));
    }
}
