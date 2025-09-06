<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use App\Services\Battle\ItemService; // novo
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\BattleBroadcaster;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class BattleActions
{
    public static function executeAction(
        array &$caster,
        ?int $skillId,
        ?int $itemId,
        $targets, // Pode ser um único alvo (array associativo) ou vários (array de arrays)
        string $battleId,
        string $casterType,
        string $targetType,
    ): void {
        $skillService = new SkillService();
        $itemService  = new ItemService(); // novo

        try {
            // Garantir array de targets
            $targetsList = is_array($targets) && isset($targets[0]) ? $targets : [$targets];
            $casterId = (string)$caster['instanceId'];
            $casterName = $caster['name'] ?? ($caster['username'] ?? 'Desconhecido');

            if ($skillId !== null) {
                $actionName = $skillService::getSkillName($skillId, $battleId, $casterType, $casterId);
                Log::channel('battle_debug')->info("[executeAction] Caster: $casterName usando SKILL $actionName", [
                    'targets' => $targetsList
                ]);

                foreach ($targetsList as &$target) {
                    $skillService->startSkillCast($caster, $target, $battleId, $skillId, $casterType, $targetType);
                }
            } elseif ($itemId !== null) {
                $actionName = $itemService::getItemName($itemId, $battleId, $casterType, $casterId);
                Log::channel('battle_debug')->info("[executeAction] Caster: $casterName usando ITEM $actionName", [
                    'targets' => $targetsList
                ]);

                foreach ($targetsList as &$target) {
                    // Lógica de aplicar item ao target
                    $itemService->applyConsumable($caster, $target, $battleId, $itemId, $casterType, $targetType);
                }

                $characterId = Redis::hget("battle:$battleId:instance_map", $caster['instanceId']);
                try {
                    $prepKey = "battle:{$characterId}:character:{$characterId}:consumables";
                    $itemService->consumeItem($battleId, $caster['instanceId'], (int)$characterId, $itemId, 1, $prepKey);
                } catch (\Throwable $e) {
                    Log::error("Falha ao decrementar item após uso", ['err' => $e->getMessage()]);
                    // decidir rollback behavior: notificar jogador, etc.
                }
            } else {
                Log::warning("[executeAction] Nenhum skillId nem itemId fornecido", [
                    'casterId' => $casterId,
                    'targets' => $targetsList
                ]);
                self::broadcastError($battleId, ["Ação inválida: nenhum skill ou item definido"]);
            }
        } catch (\Throwable $e) {
            Log::error("[BattleActions] Erro inesperado: " . $e->getMessage(), ['exception' => $e]);
            self::broadcastError($battleId, ["Erro inesperado ao executar ação"]);
        }
    }

    private static function broadcastError(string $battleId, array $messages): void
    {
        $updatePayload = [
            'players' => [],
            'enemies' => [],
            'general' => [
                'actionInfoUse' => '',
                'actionInfoResult' => '',
                'globalMessages' => $messages,
            ],
        ];
        BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
    }
}
