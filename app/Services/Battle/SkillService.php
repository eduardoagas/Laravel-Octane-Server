<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class SkillService
{
    private StaminaService $staminaService;

    private static array $skills = [
        0 => [
            'id' => 0,
            'name' => 'Attack',
            'type' => 'physical',
            'power' => 0,
            'stamina_cost' => 20,
            'pre_delay' => 0,
            'post_delay' => 500,
            'level' => 1,
        ],
        1 => [
            'id' => 1,
            'name' => 'Fire Ball',
            'type' => 'magical',
            'power' => 25,
            'stamina_cost' => 11,
            'pre_delay' => 500,
            'post_delay' => 1000,
            'level' => 1,
        ],
        2 => [
            'id' => 2,
            'name' => 'Raise Defense',
            'type' => 'buff',
            'stat' => 'physical_defense_bonus',
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
            'power' => 20,
            'stamina_cost' => 8,
            'pre_delay' => 400,
            'post_delay' => 700,
            'level' => 1,
        ],
        4 => [
            'id' => 4,
            'name' => 'Raise Defense',
            'type' => 'buff',
            'stat' => 'physical_defense_bonus',
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
        return self::$skills[$skillId]['name'] ?? 'Unknown Skill';
    }

    public static function getSkillStaminaCost(int $skillId): int
    {
        return self::$skills[$skillId]['stamina_cost'] ?? 0;
    }

    public function applySkill(
        array $caster,
        ?array $target,
        string $battleId,
        int $skillId,
        string $casterType,
        string $targetType,
    ): array {
        if (!isset(self::$skills[$skillId])) {
            throw new \InvalidArgumentException("Skill $skillId not found");
        }
        $skill = self::$skills[$skillId];
        $casterId = $caster['instanceId'];

        $casterStats = $caster['stats'] ?? [];
        if (is_string($casterStats)) $casterStats = json_decode($casterStats, true);

        // Cooldown global apenas para jogadores
        if ($casterType === 'character') {
            $this->checkCooldown($battleId, $casterId, $skill['post_delay']);
        }

        // Verifica e consome stamina (agora atômico via Lua no StaminaService)
        $currentStamina = $this->staminaService->getCurrentStamina($battleId, $casterId, $casterType);
        if ($currentStamina < $skill['stamina_cost']) {
            throw new InsufficientStaminaException("Stamina insuficiente ({$currentStamina} / {$skill['stamina_cost']})");
        }
        $currentAfterConsumption = $this->staminaService->consumeStamina($battleId, $casterId, $skill['stamina_cost'], $casterType);
        if ($currentAfterConsumption === null) {
            throw new InsufficientStaminaException("Stamina insuficiente (race condition detectada ao tentar consumir)");
        }

        if (!$target) {
            throw new \InvalidArgumentException("Target is required for skill {$skill['name']}");
        }

        $targetKey = ($targetType ?? 'character') === 'monster' ? 'monsters' : 'characters_data';
        $luaPath = storage_path("redis_scripts/battle_skill.lua");
        $luaScript = file_get_contents($luaPath);
        // NOVO: envia todos os stats e atributos para Lua, que fará o cálculo de dano/heal/buff/debuff
        $params = [
            $skill['type'],
            $casterId,
            $target['instanceId'],
            $skill['power'] ?? 0,
            $skill['stat'] ?? '',
            $skill['duration'] ?? 0,
            json_encode($casterStats),
            json_encode($target['stats'] ?? []),
            $skill['level'] ?? 1,
        ];

        $resultJson = Redis::eval($luaScript, 2, "battle:$battleId:characters_data", "battle:$battleId:monsters", ...$params);
        $result = json_decode($resultJson, true);

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        // NOVO: retorna payload final com current_stamina atualizado
        $staminaField = "{$casterType}:{$casterId}";
        $staminaKey = "battle:$battleId:stamina_data";
        $staminaRaw = Redis::hget($staminaKey, $staminaField);
        $usedStaminaTotal = null;
        if ($staminaRaw) {
            $stParsed = json_decode($staminaRaw, true);
            $usedStaminaTotal = isset($stParsed['used_stamina_total']) ? (float)$stParsed['used_stamina_total'] : null;
        }

        return [
            'battle_id' => $battleId,
            'caster_id' => $casterId,
            'skill_id' => $skillId,
            'current_stamina' => $currentAfterConsumption,
            'initial_stamina' => null,
            'used_stamina_total' => $usedStaminaTotal,
            'pre_delay' => $skill['pre_delay'],
            'post_delay' => $skill['post_delay'],
            'someoneDied' => $result['target_died'] ?? false,
            'target_hp' => $result['current_hp'] ?? null,
            'damage_dealt' => $result['damage_dealt'] ?? null,
            'healed_amount' => $result['healed_amount'] ?? null,
            'buff_applied' => $result['buff_applied'] ?? null,
            'debuff_applied' => $result['debuff_applied'] ?? null,
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
}
