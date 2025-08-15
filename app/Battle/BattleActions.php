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
    private BattleManager $battleManager;
    private SkillService $skillService;

    public function __construct(BattleManager $battleManager)
    {
        $this->battleManager = $battleManager;
        $this->skillService = new SkillService();
    }

    public function executeAction(array &$caster, int $skillId, ?array $targets, string $battleId, string $casterType): void
    {
        $targets = $targets ?? [];
        $globalMessages = [];
        $results = [];

        if (!empty($targets)) {
            // Aplica skill usando SkillService refatorado
            $results = $this->skillService->applySkill($caster, $targets, $battleId, $skillId, $casterType);

            foreach ($results as $res) {
                if (($res['hp'] ?? 0) <= 0) {
                    $globalMessages[] = $this->handleDeath($res['target_name'] ?? 'Desconhecido');
                }
            }
        }

        // Monta payload incluindo os novos instanceIds corretos
        $updatePayload = $this->buildUpdatePayload($battleId, $results, $globalMessages);

        // Broadcast
        BattleBroadcaster::broadcastToBattle($battleId, $updatePayload, 'updateYourself');
        Log::info("[BattleActions] Broadcast enviado para batalha $battleId");

        // Checa condição de vitória/derrota
        $this->battleManager->checkBattleOutcome($battleId);
    }

    private function handleDeath(string $targetName): string
    {
        return "{$targetName} morreu!";
    }

    private function buildUpdatePayload(string $battleId, array $results, array $globalMessages): array
    {
        // Players
        $playersPayload = [];
        foreach (Redis::hgetall("battle:$battleId:characters_data") as $instanceId => $playerJson) {
            $playerData = json_decode($playerJson, true);
            $playersPayload[] = [
                'instanceId' => $playerData['instanceId'] ?? $instanceId, // garante char_ prefix
                'currentHp' => (int)($playerData['hp'] ?? 0),
            ];
        }

        // Enemies
        $enemiesPayload = [];
        foreach (Redis::hgetall("battle:$battleId:monsters") as $instanceId => $monsterJson) {
            $monsterData = json_decode($monsterJson, true);
            $enemiesPayload[] = [
                'instanceId' => $monsterData['instanceId'] ?? $instanceId, // garante mon_ prefix
                'isAlive' => ($monsterData['hp'] ?? 0) > 0,
                'currentHp' => (int)($monsterData['hp'] ?? 0),
                'maxHp' => (int)($monsterData['maxhp'] ?? 0),
            ];
        }

        // Ações
        $actionInfoUseMessages = array_map(fn($r) => $r['action_info_use'] ?? '', $results);
        $actionInfoResultMessages = array_map(fn($r) => $r['action_info_result'] ?? '', $results);

        return [
            'players' => $playersPayload,
            'enemies' => $enemiesPayload,
            'general' => [
                'actionInfoUse' => implode("\n", array_filter($actionInfoUseMessages)),
                'actionInfoResult' => implode("\n", array_filter($actionInfoResultMessages)),
                'globalMessages' => $globalMessages,
            ],
        ];
    }


    public function executeActionSafe(array &$caster, int $skillId, ?array $targets, string $battleId, string $casterType): void
    {
        try {
            $this->executeAction($caster, $skillId, $targets, $battleId, $casterType);
        } catch (InsufficientStaminaException $e) {
            $this->broadcastError($battleId, ["Stamina insuficiente"]);
        } catch (SkillCooldownException $e) {
            $this->broadcastError($battleId, ["Skill em cooldown"]);
        } catch (\Throwable $e) {
            Log::error("[BattleActions] Erro inesperado: " . $e->getMessage(), ['exception' => $e]);
            $this->broadcastError($battleId, ["Erro inesperado ao executar ação"]);
        }
    }

    private function broadcastError(string $battleId, array $messages): void
    {
        $payload = [
            'players' => [],
            'enemies' => [],
            'general' => [
                'actionInfoUse' => '',
                'actionInfoResult' => '',
                'globalMessages' => $messages,
            ],
        ];
        BattleBroadcaster::broadcastToBattle($battleId, $payload, 'updateYourself');
    }
}
