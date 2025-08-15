<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\SkillService;
use App\Services\Battle\BattleBroadcaster;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class BattleActions
{
    public static function executeAction(
        array &$caster,
        int $skillId,
        array|null &$targets, // agora pode ser array de alvos ou um único alvo
        string $battleId,
        string $casterType // 'character' ou 'monster'
    ): void {
        $skillService = new SkillService();
        $globalMessages = [];

        try {
            // Força transformar targets em array
            $targets = is_null($targets) ? [] : (array)$targets;

            $results = [];
            foreach ($targets as &$target) {
                $results[] = $skillService->applySkill($caster, $target, $battleId, $skillId, $casterType);
                if (isset($target['hp']) && $target['hp'] <= 0) {
                    $targetName = $target['name'] ?? ($target['username'] ?? 'Desconhecido');
                    $globalMessages[] = "{$targetName} morreu!";
                }
            }

            // Payload para broadcast
            $playersPayload = [];
            foreach (Redis::hgetall("battle:$battleId:characters_data") as $playerId => $playerJson) {
                $playerData = json_decode($playerJson, true);
                $playersPayload[] = [
                    'instanceId' => (string)$playerId,
                    'currentHp' => (int)($playerData['hp'] ?? 0),
                ];
            }

            $enemiesPayload = [];
            foreach (Redis::hgetall("battle:$battleId:monsters") as $monsterId => $monsterJson) {
                $monsterData = json_decode($monsterJson, true);
                $enemiesPayload[] = [
                    'instanceId' => (string)$monsterId,
                    'isAlive' => isset($monsterData['hp']) && $monsterData['hp'] > 0,
                ];
            }

            $updatePayload = [
                'players' => $playersPayload,
                'enemies' => $enemiesPayload,
                'general' => [
                    'actionInfoUse' => $results[0]['action_info_use'] ?? '',
                    'actionInfoResult' => $results[0]['action_info_result'] ?? '',
                    'globalMessages' => $globalMessages,
                ],
            ];

            BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');

            Log::info("[BattleActions] Broadcast enviado para batalha $battleId");
        } catch (InsufficientStaminaException $e) {
            $globalMessages[] = "Stamina insuficiente";
            self::broadcastError($battleId, $globalMessages);
        } catch (SkillCooldownException $e) {
            $globalMessages[] = "Skill em cooldown";
            self::broadcastError($battleId, $globalMessages);
        } catch (\Throwable $e) {
            Log::error("[BattleActions] Erro inesperado: " . $e->getMessage(), ['exception' => $e]);
            $globalMessages[] = "Erro inesperado ao executar ação";
            self::broadcastError($battleId, $globalMessages);
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
