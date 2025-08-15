<?php

namespace App\Services\Battle;

use App\Battle\BattleManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\Battle\StaminaService;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;


class SkillService
{
    private StaminaService $staminaService;

    private array $skills = [
        0 => ['id' => 0, 'name' => 'Attack', 'type' => 'physical', 'power' => 0, 'stamina_cost' => 10, 'pre_delay' => 0, 'post_delay' => 500],
        1 => ['id' => 1, 'name' => 'Fire Ball', 'type' => 'magical', 'power' => 25, 'stamina_cost' => 5, 'pre_delay' => 500, 'post_delay' => 1000],
        2 => ['id' => 2, 'name' => 'Raise Defense', 'type' => 'buff', 'stat' => 'defense', 'bonus' => 5, 'duration' => 3, 'stamina_cost' => 10, 'pre_delay' => 300, 'post_delay' => 500],
        3 => ['id' => 3, 'name' => 'Heal', 'type' => 'heal', 'power' => 20, 'stamina_cost' => 8, 'pre_delay' => 400, 'post_delay' => 700],
        4 => ['id' => 4, 'name' => 'Wait', 'type' => 'buff', 'stat' => 'defense', 'bonus' => 0, 'duration' => 3, 'stamina_cost' => 10, 'pre_delay' => 300, 'post_delay' => 500],
    ];

    public function __construct()
    {
        $this->staminaService = new StaminaService();
    }

    /**
     * Aplica uma skill em alvos já resolvidos.
     * 
     * @param array $caster
     * @param array $targets  <- agora sempre recebe alvos resolvidos
     * @param string $battleId
     * @param int $skillId
     * @param string $casterType
     * @return array
     */
    public function applySkill(array $caster, array $targets, string $battleId, int $skillId, string $casterType): array
    {
        if (!isset($this->skills[$skillId])) {
            throw new \InvalidArgumentException("Skill $skillId not found");
        }

        $skill = $this->skills[$skillId];
        $casterId = $caster['instanceId'];

        // Checa cooldown
        $this->checkCooldown($battleId, $casterId, $skill['post_delay']);

        // Checa stamina
        $currentStamina = $this->staminaService->getCurrentStamina($battleId, $casterId, $casterType);
        if ($currentStamina < $skill['stamina_cost']) {
            throw new InsufficientStaminaException("Stamina insuficiente ({$currentStamina} / {$skill['stamina_cost']})");
        }

        $this->staminaService->consumeStamina($battleId, $casterId, $skill['stamina_cost'], $casterType);

        // Aplica a skill em cada alvo
        $results = [];
        foreach ($targets as &$target) {
            $resultPayload = $this->applySkillToTarget($caster, $target, $skill, $battleId, $casterType);
            $this->saveEntityState($battleId, $target);

            $actionUse = sprintf("%s uses %s!", $caster['name'] ?? 'Unknown', $skill['name']);
            $actionResult = $this->buildActionResultMessage($skill['type'], $caster, $target, $resultPayload);

            $results[] = [
                'target_id' => $target['instanceId'] ?? null,
                'target_name' => $target['name'] ?? $target['username'] ?? 'Desconhecido',
                'hp' => $target['hp'] ?? null,
                'result_payload' => $resultPayload,
                'action_info_use' => $actionUse,
                'action_info_result' => $actionResult,
            ];
        }

        return $results;
    }

    public function applySkillToTarget(array $caster, array &$target, array $skill, string $battleId, string $casterType): array
    {
        $resultPayload = [];

        if ($skill['type'] === 'physical' || $skill['type'] === 'magical') {
            $attackAttribute = $skill['type'] === 'physical' ? 'pattack' : 'mattack';
            $baseAttack = $caster[$attackAttribute] ?? 0;
            $power = $skill['power'] + $baseAttack;

            $damage = max(0, $power - ($target['defense'] ?? 0));
            $target['hp'] = max(0, ($target['hp'] ?? 0) - $damage);
            $resultPayload['damage_dealt'] = $damage;
        } elseif ($skill['type'] === 'heal') {
            $currentHp = $target['hp'] ?? 0;
            $maxHp = $target['maxhp'] ?? 100;
            $healAmount = $skill['power'];
            $newHp = min($maxHp, $currentHp + $healAmount);
            $actualHealed = $newHp - $currentHp;
            $target['hp'] = $newHp;
            $resultPayload['healed_amount'] = $actualHealed;
        } elseif ($skill['type'] === 'buff') {
            $buff = [
                'skill_id' => $skill['id'],
                'stat' => $skill['stat'],
                'bonus' => $skill['bonus'],
                'duration' => $skill['duration'],
            ];
            Redis::hset("battle:{$battleId}:buffs", $target['instanceId'], json_encode($buff));
            $resultPayload['buff_applied'] = $buff;
        }

        return $resultPayload;
    }

    // --- Métodos auxiliares ---
    private function checkCooldown(string $battleId, string $casterId, int $postDelay)
    {
        $redisKey = "global_cooldown_at:{$battleId}:{$casterId}";
        $now = now()->timestamp;
        $readyAt = Redis::get($redisKey);
        if ($readyAt && $now < (int)$readyAt) {
            throw new SkillCooldownException("Skill em cooldown até " . date('H:i:s', (int)$readyAt));
        }
        Redis::set($redisKey, $now + (int)($postDelay / 1000));
    }

    private function saveEntityState(string $battleId, array $entity)
    {
        $instanceId = $entity['instanceId'] ?? null;
        if (!$instanceId) {
            Log::error("saveEntityState: entity missing instanceId", ['entity' => $entity]);
            return;
        }

        if (($entity['type'] ?? null) === 'monster' || isset($entity['monster_id'])) {
            Redis::hset("battle:{$battleId}:monsters", $instanceId, json_encode($entity));
            Log::info("Monster HP saved", ['instanceId' => $instanceId, 'hp' => $entity['hp'] ?? null, 'battleId' => $battleId]);
        } else {
            Redis::hset("battle:{$battleId}:characters_data", $instanceId, json_encode($entity));
            Log::info("Character HP saved", ['instanceId' => $instanceId, 'hp' => $entity['hp'] ?? null, 'battleId' => $battleId]);
        }
    }

    private function buildActionResultMessage(string $type, array $caster, ?array $target, array $resultPayload): string
    {
        $casterName = $caster['name'] ?? 'Unknown';
        $targetName = $target['name'] ?? 'Unknown';

        return match ($type) {
            'physical', 'magical' => sprintf("%s inflicts %d damage on %s!", $casterName, $resultPayload['damage_dealt'] ?? 0, $targetName),
            'heal' => sprintf("%s heals %s (%d HP)!", $casterName, $targetName, $resultPayload['healed_amount'] ?? 0),
            'buff' => sprintf("%s applies %s buff to %s!", $casterName, ucfirst($resultPayload['buff_applied']['stat'] ?? 'unknown'), $targetName),
            default => ''
        };
    }
}
