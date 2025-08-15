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
        int $skillId,       // antes era string $action
        ?array &$target,
        string $battleId,
        string $casterType
    ): void {
        $skillService = new SkillService();
        // Não precisa mais mapear
        $globalMessages = [];

        try {
            $result = $skillService->applySkill($caster, $target, $battleId, $skillId, $casterType);

            $casterName = $caster['name'] ?? ($caster['username'] ?? 'Desconhecido');
            $skillName = $skillService->getSkillName($skillId);
            $targetName = $target['name'] ?? ($target['username'] ?? 'Desconhecido');
            $targetTypeStr = ($target['type'] ?? 'character') === 'monster' ? 'Monstro' : 'Jogador';

            // Monta mensagens de ação
            $actionInfoUse = $result['action_info_use'];
            $actionInfoResult = $result['action_info_result'];

            if (isset($result['damage_dealt'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu dano de " . $result['damage_dealt'];
            } elseif (isset($result['healed_amount'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu cura de " . $result['healed_amount'];
            } elseif (isset($result['buff_applied'])) {
                $buff = $result['buff_applied'];
                $actionInfoResult = "Buff aplicado: +{$buff['bonus']} {$buff['stat']} por {$buff['duration']} turnos";
            }

            if ($target && isset($target['hp']) && $target['hp'] <= 0) {
                $globalMessages[] = "{$targetName} morreu!";
            }

            // Payload para broadcast
            $playersPayload = [];
            $enemiesPayload = [];

            $playersRaw = Redis::hgetall("battle:$battleId:characters_data");
            foreach ($playersRaw as $playerId => $playerJson) {
                $playerData = json_decode($playerJson, true);
                $playersPayload[] = [
                    'instanceId' => (string)$playerId,
                    'currentHp' => (int)($playerData['hp'] ?? 0),
                ];
            }

            $monstersRaw = Redis::hgetall("battle:$battleId:monsters");

            foreach ($monstersRaw as $monsterId => $monsterJson) {
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
                    'actionInfoUse' => $actionInfoUse,
                    'actionInfoResult' => $actionInfoResult,
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
