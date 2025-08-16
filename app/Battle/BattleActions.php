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
        string $casterType
    ): bool { // retorna true se alguém morrer {
        $skillService = new SkillService();
        $globalMessages = [];
        $someoneDied = false; // flag para retornar

        try {
            // Garantir que sempre teremos um array de targets
            $targetsList = is_array($targets) && isset($targets[0]) ? $targets : [$targets];

            $casterName = $caster['name'] ?? ($caster['username'] ?? 'Desconhecido');
            $skillName = $skillService->getSkillName($skillId);

            $allResults = [];

            Log::channel('battle_debug')->info("[executeAction] Caster: $casterName usando skill $skillName", [
                'targets' => $targetsList
            ]);

            foreach ($targetsList as &$target) {
                $result = $skillService->applySkill($caster, $target, $battleId, $skillId, $casterType);

                $targetName = $target['name'] ?? ($target['username'] ?? 'Desconhecido');
                $targetTypeStr = ($target['type'] ?? 'character') === 'monster' ? 'Monstro' : 'Jogador';

                $actionInfoUse = $result['action_info_use'] ?? "$casterName usou $skillName";
                $actionInfoResult = $result['action_info_result'] ?? '';

                if (isset($result['damage_dealt'])) {
                    $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu dano de {$result['damage_dealt']}";
                } elseif (isset($result['healed_amount'])) {
                    $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu cura de {$result['healed_amount']}";
                } elseif (isset($result['buff_applied'])) {
                    $buff = $result['buff_applied'];
                    $actionInfoResult = "Buff aplicado: +{$buff['bonus']} {$buff['stat']} por {$buff['duration']} turnos";
                }

                if ($target && isset($target['hp']) && $target['hp'] <= 0) {
                    $globalMessages[] = "{$targetName} morreu!";
                    $someoneDied = true; // marca que alguém morreu
                }

                $allResults[] = [
                    'actionInfoUse' => $actionInfoUse,
                    'actionInfoResult' => $actionInfoResult
                ];

                Log::channel('battle_debug')->error("[executeAction] Result aplicado", [
                    'target' => $targetName,
                    'result' => $result
                ]);
            }

            // Estado atualizado dos personagens
            $playersPayload = [];
            foreach (Redis::hgetall("battle:$battleId:characters_data") as $playerId => $playerJson) {
                $playerData = json_decode($playerJson, true);
                $playersPayload[] = [
                    'instanceId' => (string)$playerId,
                    'currentHp' => (int)($playerData['hp'] ?? 0),
                ];
            }

            // Estado atualizado dos monstros
            $enemiesPayload = [];
            foreach (Redis::hgetall("battle:$battleId:monsters") as $monsterId => $monsterJson) {
                $monsterData = json_decode($monsterJson, true);
                $enemiesPayload[] = [
                    'instanceId' => (string)$monsterId,
                    'isAlive' => isset($monsterData['hp']) && $monsterData['hp'] > 0,
                ];
            }

            // Monta payload final
            $updatePayload = [
                'players' => $playersPayload,
                'enemies' => $enemiesPayload,
                'general' => [
                    'actionInfoUse' => implode(' | ', array_column($allResults, 'actionInfoUse')),
                    'actionInfoResult' => implode(' | ', array_column($allResults, 'actionInfoResult')),
                    'globalMessages' => $globalMessages,
                ],
            ];

            BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
            Log::info("[BattleActions] Broadcast enviado para batalha $battleId");
        } catch (InsufficientStaminaException $e) {
            self::broadcastError($battleId, ["Stamina insuficiente"]);
        } catch (SkillCooldownException $e) {
            self::broadcastError($battleId, ["Skill em cooldown"]);
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
