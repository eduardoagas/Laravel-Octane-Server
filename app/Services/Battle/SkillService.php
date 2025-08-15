<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class SkillService
{
    private StaminaService $staminaService;

    private array $skills = [
        0 => [
            'id' => 0,
            'name' => 'Attack',
            'type' => 'physical',
            'power' => 0,
            'stamina_cost' => 10,
            'pre_delay' => 0,
            'post_delay' => 500,
        ],
        1 => [
            'id' => 1,
            'name' => 'Fire Ball',
            'type' => 'magical',
            'power' => 25,
            'stamina_cost' => 5,
            'pre_delay' => 500,
            'post_delay' => 1000,
        ],
        2 => [
            'id' => 2,
            'name' => 'Raise Defense',
            'type' => 'buff',
            'stat' => 'defense',
            'bonus' => 5,
            'duration' => 3,
            'stamina_cost' => 10,
            'pre_delay' => 300,
            'post_delay' => 500,
        ],
        3 => [
            'id' => 3,
            'name' => 'Heal',
            'type' => 'heal',
            'power' => 20, // quantidade de HP curada
            'stamina_cost' => 8,
            'pre_delay' => 400,
            'post_delay' => 700,
        ],
        4 => [
            'id' => 4,
            'name' => 'Raise Defense',
            'type' => 'buff',
            'stat' => 'defense',
            'bonus' => 0,
            'duration' => 3,
            'stamina_cost' => 0,
            'pre_delay' => 300,
            'post_delay' => 500,
        ]
    ];

    public function __construct()
    {
        $this->staminaService = new StaminaService();
    }
    public function getSkillName(int $skillId): string
    {
        return $this->skills[$skillId]['name'] ?? 'Unknown Skill';
    }

    public function applySkill(
        array $caster,
        ?array $target, //'character' ou 'monster'
        string $battleId,
        int $skillId,
        string $casterType, // 'character' ou 'monster'
       
    ): array {
        if (!isset($this->skills[$skillId])) {
            throw new \InvalidArgumentException("Skill $skillId not found");
        }

        $skill = $this->skills[$skillId];
        $casterId = $caster['instanceId'];

        // Cooldown global apenas para jogadores
        if ($casterType === 'character') {
            $this->checkCooldown($battleId, $casterId, $skill['post_delay']);
        }

        // Verifica stamina
        $currentStamina = $this->staminaService->getCurrentStamina($battleId, $casterId, $casterType);
        if ($currentStamina < $skill['stamina_cost']) {
            throw new InsufficientStaminaException("Stamina insuficiente ({$currentStamina} / {$skill['stamina_cost']})");
        }

        // Consome stamina
        $this->staminaService->consumeStamina($battleId, $casterId, $skill['stamina_cost'], $casterType);

        $resultPayload = [];

        if ($skill['type'] === 'physical' || $skill['type'] === 'magical') {
            if (!$target) {
                throw new \InvalidArgumentException("Target is required for damage skills");
            }

            $attackAttribute = $skill['type'] === 'physical' ? 'pattack' : 'mattack';
            $baseAttack = $caster[$attackAttribute] ?? 0;
            $power = $skill['power'] + $baseAttack;

            $damage = max(0, $power - ($target['defense'] ?? 0));
            $target['hp'] = max(0, ($target['hp'] ?? 0) - $damage);

            $this->saveEntityState($battleId, $target);

            $resultPayload['target_hp'] = $target['hp'];
            $resultPayload['damage_dealt'] = $damage;
        } elseif ($skill['type'] === 'buff') {
            $buff = [
                'skill_id' => $skillId,
                'stat' => $skill['stat'],
                'bonus' => $skill['bonus'],
                'duration' => $skill['duration'],
            ];
            Redis::hset("battle:{$battleId}:buffs", $casterId, json_encode($buff));
            $resultPayload['buff_applied'] = $buff;
        } elseif ($skill['type'] === 'heal') {
            if (!$target) {
                throw new \InvalidArgumentException("Target is required for heal skills");
            }

            $currentHp = $target['hp'] ?? 0;
            $maxHp = $target['max_hp'] ?? 100; // default max_hp se não existir

            $healAmount = $skill['power'];
            $newHp = min($maxHp, $currentHp + $healAmount);
            $actualHealed = $newHp - $currentHp;

            $target['hp'] = $newHp;
            $this->saveEntityState($battleId, $target);

            $resultPayload['target_hp'] = $newHp;
            $resultPayload['healed_amount'] = $actualHealed;
        }

        return [
            'battle_id' => $battleId,
            'caster_id' => $casterId,
            'skill_id' => $skillId,
            'current_stamina' => $this->staminaService->getCurrentStamina($battleId, $casterId, $casterType),
            'pre_delay' => $skill['pre_delay'],
            'post_delay' => $skill['post_delay'],
            'action_info_use' => sprintf(
                "%s uses %s!",
                $caster['name'] ?? 'Unknown',
                $skill['name']
            ),
            'action_info_result' => $this->buildActionResultMessage($skill['type'], $caster, $target, $resultPayload)
        ];
    }

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

        // Decide se é monstro ou personagem
        if (($entity['type'] ?? null) === 'monster' || isset($entity['monster_id'])) {
            Redis::hset("battle:{$battleId}:monsters", (string)$instanceId, json_encode($entity));

            // Log do HP atual do monstro
            $hp = $entity['hp'] ?? null;
            Log::info("Monster HP saved", [
                'instanceId' => $instanceId,
                'hp' => $hp,
                'battleId' => $battleId,
            ]);
        } else {
            Redis::hset("battle:{$battleId}:characters_data", (string)$instanceId, json_encode($entity));

            // Log do HP atual do personagem
            $hp = $entity['hp'] ?? null;
            Log::info("Character HP saved", [
                'instanceId' => $instanceId,
                'hp' => $hp,
                'battleId' => $battleId,
            ]);
        }
    }



    private function buildActionResultMessage(string $type, array $caster, ?array $target, array $resultPayload): string
    {
        $casterName = $caster['name'] ?? 'Unknown';
        $targetName = $target['name'] ?? 'Unknown';

        return match ($type) {
            'physical', 'magical' =>
            sprintf("%s inflicts %d damage on %s!", $casterName, $resultPayload['damage_dealt'] ?? 0, $targetName),
            'heal' =>
            sprintf("%s heals %s (%d HP)!", $casterName, $targetName, $resultPayload['healed_amount'] ?? 0),
            'buff' =>
            sprintf("%s applies %s buff to %s!", $casterName, ucfirst($resultPayload['buff_applied']['stat'] ?? 'unknown'), $targetName),
            default =>
            ''
        };
    }
}
