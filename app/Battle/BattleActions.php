<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\BattleBroadcaster;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class BattleActions
{
    public static function executeAction(
        array &$caster,
        int $skillId,
        $targets, // Pode ser um único alvo (array associativo) ou vários (array de arrays)
        string $battleId,
        string $casterType,
        string $targetType,
    ): bool {
        $skillService = new SkillService();
        $globalMessages = [];
        $someoneDied = false;

        try {
            // Garantir array de targets
            $targetsList = is_array($targets) && isset($targets[0]) ? $targets : [$targets];
            $casterId = (string)$caster['instanceId'];
            $casterName = $caster['name'] ?? ($caster['username'] ?? 'Desconhecido');
            $skillName = $skillService::getSkillName($skillId, $battleId, $casterType, $casterId);

            $allResults = [];
            $staminaUpdates = [];
            $casterCurrentStamina = null;

            Log::channel('battle_debug')->info("[executeAction] Caster: $casterName usando skill $skillName", [
                'targets' => $targetsList
            ]);

            foreach ($targetsList as &$target) {
                Log::channel('battle_debug')->info("[executeAction] Target BEFORE applySkill", [
                    'targetId' => $target['instanceId'] ?? null,
                    'targetType' => $targetType,
                    'targetData' => $target,
                    'casterId' => $caster['instanceId'] ?? null
                ]);

                Log::info("[debug] characters_data lookup", [
                    'key' => "battle:$battleId:characters_data",
                    'targetKey' => $target['instanceId'],
                    'exists' => Redis::hexists("battle:$battleId:characters_data", $target['instanceId'])
                ]);

                // Avisa cast de skill (pode ser ação normal ou tick)
                $skillService->startSkillCast($caster, $target, $battleId, $skillId, $casterType, $targetType);
            }
        } catch (\Throwable $e) {
            Log::error("[BattleActions] Erro inesperado: " . $e->getMessage(), ['exception' => $e]);
            self::broadcastError($battleId, ["Erro inesperado ao executar ação"]);
        }

        return $someoneDied;
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
