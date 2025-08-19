<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class SkillService
{
    private StaminaService $staminaService;

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
        $casterId = $caster['instanceId'];
        // 1️⃣ Busca as skills do Redis
        $redisSkillKey = "battle:$battleId:{$casterType}:{$casterId}:skills";
        $skillsRaw = Redis::get($redisSkillKey);
        if (!$skillsRaw) {
            throw new \RuntimeException("No skills loaded in Redis for $casterType:$casterId");
        }

        $skillsArray = json_decode($skillsRaw, true);
        if (!$skillsArray || !is_array($skillsArray)) {
            throw new \RuntimeException("Invalid skills data in Redis for $casterType:$casterId");
        }

        // 2️⃣ Localiza a skill pelo skillId
        $skill = null;
        foreach ($skillsArray as $s) {
            if ((int)($s['id'] ?? -1) === $skillId) {
                $skill = $s;
                break;
            }
        }
        if (!$skill) {
            throw new \InvalidArgumentException("Skill $skillId not found in Redis for $casterType:$casterId");
        }



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
