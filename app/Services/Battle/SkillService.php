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
            'power' => 25, //15-25
            'stamina_cost' => 11, //5-12
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
            'power' => 20, // quantidade de HP curada
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
        $cost = self::$skills[$skillId]['stamina_cost'] ?? 0;
        return $cost;
    }

    public function applySkill(
        array $caster,
        ?array $target,
        string $battleId,
        int $skillId,
        string $casterType, // 'character' ou 'monster'
        string $targetType,
    ): array {
        if (!isset(self::$skills[$skillId])) {
            throw new \InvalidArgumentException("Skill $skillId not found");
        }

        $skill = self::$skills[$skillId];
        $casterId = $caster['instanceId'];

        // NOVO: garante que atributos do caster venham de stats
        $casterStats = $caster['stats'] ?? [];
        if (is_string($casterStats)) $casterStats = json_decode($casterStats, true);

        // Cooldown global apenas para jogadores
        if ($casterType === 'character') {
            $this->checkCooldown($battleId, $casterId, $skill['post_delay']);
        }

        // Verifica stamina (leitura inicial)
        $currentStamina = $this->staminaService->getCurrentStamina($battleId, $casterId, $casterType);
        if ($currentStamina < $skill['stamina_cost']) {
            // Se já estava insuficiente, corta aqui (mantém comportamento anterior).
            throw new InsufficientStaminaException(
                "Stamina insuficiente ({$currentStamina} / {$skill['stamina_cost']})"
            );
        }

        // Consome stamina (NOVO: operação atômica via Lua no StaminaService)
        $currentAfterConsumption = $this->staminaService->consumeStamina(
            $battleId,
            $casterId,
            $skill['stamina_cost'],
            $casterType
        );

        if ($currentAfterConsumption === null) {
            throw new InsufficientStaminaException(
                "Stamina insuficiente (race condition detectada ao tentar consumir)"
            );
        }

        if (!$target) {
            throw new \InvalidArgumentException("Target is required for skill {$skill['name']}");
        }

        $targetKey = ($targetType ?? 'character') === 'monster' ? 'monsters' : 'characters_data';
        $redisKey = "battle:$battleId:$targetKey";
        $luaPath = storage_path("redis_scripts/battle_skill.lua");
        $luaScript = file_get_contents($luaPath);

        if ($skill['type'] === 'physical' || $skill['type'] === 'magical') {
            $attackAttribute = $skill['type'] === 'physical' ? 'strength' : 'intelligence';
            $baseAttack = $casterStats[$attackAttribute] ?? 0;
            $damage = $skill['power'] + $baseAttack;
            $strength = match ($skill['level']) {
                1 => 'weak',
                2 => 'medium',
                3 => 'strong',
                default => 'medium',
            };
            $power = $this->calculateDamage($damage, $strength);
        } elseif ($skill['type'] === 'buff') {
            $power = $skill['bonus'];
        } elseif ($skill['type'] === 'heal') {
            $power = $skill['power'] + $casterStats['intelligence'];
        }

        $resultJson = Redis::eval(
            $luaScript,
            1,
            $redisKey,
            $skill['type'],
            $casterId,
            $target['instanceId'],
            $power,
            $skill['stat'] ?? '',
            $skill['duration'] ?? 0
        );

        $result = json_decode($resultJson, true);

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

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
        ];
    }

    private function checkCooldown(string $battleId, string $casterId, int $postDelay)
    {
        $redisKey = "global_cooldown_at:{$battleId}:{$casterId}";
        $now = now()->timestamp;
        $readyAt = Redis::get($redisKey);
        if ($readyAt && $now < (int)$readyAt) {
            throw new SkillCooldownException(
                "Skill em cooldown até " . date('H:i:s', (int)$readyAt)
            );
        }
        Redis::set($redisKey, $now + (int)($postDelay / 1000));
    }

    private function calculateDamage(float $power, string $strength = 'weak'): float
    {
        $base = 10 + 1 * $power; // Multiplicadores sugeridos
        $multipliers = [
            'weak' => 0.9,
            'medium' => 3.0,
            'strong' => 5.0,
        ];
        $mult = $multipliers[$strength] ?? 0.9;
        return $base * $mult;
    }
}
