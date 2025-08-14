<?php

namespace App\Battle;

use Illuminate\Support\Facades\Log;
use App\Services\Battle\SkillService;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\SkillCooldownException;
use App\Services\Battle\BattleBroadcaster;
use App\Exceptions\InsufficientStaminaException;

class BattleActions
{
    public static function executeAction(
        array &$caster,
        string $action,
        ?array &$target,
        string $battleId,
        string $casterType // 'monster' ou 'character'
    ): void {
        $skillService = new SkillService();
        $skillId = self::mapActionToSkillId($action);

        $globalMessages = [];
        $casterName = $caster['name'] ?? $caster['username'] ?? 'Desconhecido';
        Log::info("[BattleActions] Executando ação '{$action}' do {$casterType} '{$casterName}'");

        try {
            $result = $skillService->applySkill($caster, $target, $battleId, $skillId, $casterType);
            Log::info("[BattleActions] Skill aplicada, resultado:", $result);

            if ($target && isset($target['id'])) {
                Log::info("[BattleActions] Atualizando target {$target['id']}");
                if (($target['type'] ?? '') === 'monster') {
                    $updatedTargetJson = Redis::hget("battle:$battleId:monsters", $target['instanceId']);
                } else {
                    $updatedTargetJson = Redis::hget("battle:$battleId:characters_data", $target['id']);
                }
                if ($updatedTargetJson) {
                    $target = json_decode($updatedTargetJson, true);
                    Log::info("[BattleActions] Target atualizado via Redis:", $target);
                }
            }

            $casterName = $caster['name'] ?? ($caster['username'] ?? 'Desconhecido');
            $skillName = $skillService->getSkillName($skillId);
            $actionInfoUse = ($casterType === 'monster' ? 'Monstro' : 'Jogador') . " {$casterName} utilizou skill {$skillName}";

            $actionInfoResult = '';
            $targetName = $target['name'] ?? ($target['username'] ?? 'Desconhecido');
            $targetTypeStr = ($target['type'] ?? 'character') === 'monster' ? 'Monstro' : 'Jogador';

            if (isset($result['result']['damage_dealt'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu dano de " . $result['result']['damage_dealt'];
            } elseif (isset($result['result']['healed'])) {
                $actionInfoResult = "{$targetTypeStr} {$targetName} recebeu cura de " . $result['result']['healed'];
            } elseif (isset($result['result']['buff_applied'])) {
                $buff = $result['result']['buff_applied'];
                $actionInfoResult = "Buff aplicado: +{$buff['bonus']} {$buff['stat']} por {$buff['duration']} turnos";
            }

            if ($target && isset($target['hp']) && $target['hp'] <= 0) {
                $globalMessages[] = ($target['type'] ?? 'character') === 'monster'
                    ? "{$targetName} morreu!"
                    : "{$targetName} morreu!";
                Log::info("[BattleActions] Target morreu:", $target);
            }

            $updatePayload = [
                'players' => [],
                'enemies' => [],
                'general' => [
                    'actionInfoUse' => $actionInfoUse,
                    'actionInfoResult' => $actionInfoResult,
                    'globalMessages' => $globalMessages,
                ],
            ];

            if ($target) {
                $updatePayload['players'][] = [
                    'instanceId' => (string) $target['id'],
                    'currentHp' => (int) ($target['hp'] ?? 0),
                ];
                Log::info("[BattleActions] Payload do player preparado:", $updatePayload['players']);
            }

            BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
            Log::info("[BattleActions] Broadcast enviado para batalha {$battleId}");
        } catch (InsufficientStaminaException $e) {
            Log::warning("[BattleActions] Stamina insuficiente: {$e->getMessage()}");
            $globalMessages[] = "Stamina insuficiente";
            $updatePayload = [
                'actionInfoUse' => '',
                'actionInfoResult' => '',
                'globalMessages' => $globalMessages,
                'players' => [],
            ];
            BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
        } catch (SkillCooldownException $e) {
            Log::warning("[BattleActions] Skill em cooldown: {$e->getMessage()}");
            $globalMessages[] = "Skill em cooldown";
            $updatePayload = [
                'actionInfoUse' => '',
                'actionInfoResult' => '',
                'globalMessages' => $globalMessages,
                'players' => [],
            ];
            BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
        } catch (\Throwable $e) {
            Log::error("[BattleActions] Erro inesperado: " . $e->getMessage(), ['exception' => $e]);
            $globalMessages[] = "Erro inesperado ao executar ação";
            $updatePayload = [
                'actionInfoUse' => '',
                'actionInfoResult' => '',
                'globalMessages' => $globalMessages,
                'players' => [],
            ];
            BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
        }
    }

    private static function mapActionToSkillId(string $action): int
    {
        return match ($action) {
            'attack' => 0,
            'special_skill' => 1,
            'wait' => 4,
            default => throw new \InvalidArgumentException("Unknown action '$action'")
        };
    }
}
