<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;

class SkillService
{
    private StaminaService $staminaService;

    /**
     * Se ainda usar skills em memória como fallback.
     * Mantive seu array original (se existir no seu código, mantenha-o aqui)
     */
    private static array $skills = [
        /*0 => [
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
        ],*/
        // ... outros como fallback, se quiser
    ];

    public function __construct()
    {
        $this->staminaService = new StaminaService();
    }

    /**
     * Tenta obter o nome de uma skill:
     * - se informado contexto (battleId + casterType + casterId), tenta buscar no Redis
     * - caso contrário, tenta no array estático como fallback, gerando log de erro
     */
    public static function getSkillName(int $skillId, ?string $battleId = null, ?string $casterType = null, ?string $casterId = null): string
    {
        if ($battleId && $casterType && $casterId) {
            $s = self::findSkillInRedisStatic($battleId, $casterType, $casterId, $skillId);
            if ($s) {
                return $s['name'] ?? 'Unknown Skill';
            }
        }

        // Fallback: log de erro
        \Illuminate\Support\Facades\Log::error("Skill $skillId fallback usado no array estático. Contexto: ", [
            'battle_id' => $battleId,
            'caster_type' => $casterType,
            'caster_id' => $casterId,
        ]);

        return self::$skills[$skillId]['name'] ?? 'Unknown Skill';
    }

    /**
     * Mesma ideia para custo de stamina
     */
    public static function getSkillStaminaCost(int $skillId, ?string $battleId = null, ?string $casterType = null, ?string $casterId = null): int
    {
        if ($battleId && $casterType && $casterId) {
            $s = self::findSkillInRedisStatic($battleId, $casterType, $casterId, $skillId);
            if ($s) {
                return (int)($s['stamina_cost'] ?? 0);
            }
        }

        // Fallback: log de erro
        \Illuminate\Support\Facades\Log::error("Skill $skillId fallback de stamina usado no array estático. Contexto: ", [
            'battle_id' => $battleId,
            'caster_type' => $casterType,
            'caster_id' => $casterId,
        ]);

        return (int)(self::$skills[$skillId]['stamina_cost'] ?? 0);
    }

    /**
     * Aplica skill: agora usa as skills do Redis (por caster) e só faz fallback para memória.
     *
     * $caster e $target são arrays com ao menos 'instanceId' e 'stats' (possivelmente JSON)
     */
    public function applySkill(
        array $caster,
        ?array $target,
        string $battleId,
        int $skillId,
        string $casterType, // 'character' ou 'monster'
        string $targetType,
    ): array {
        $casterId = (string)$caster['instanceId'];

        // 1️⃣ Busca a skill no Redis para este caster
        $skill = $this->findSkillInRedis($battleId, $casterType, $casterId, $skillId);

        // 2️⃣ Fallback para array estático em memória (se existir)
        if (!$skill) {
            if (isset(self::$skills[$skillId])) {
                $skill = self::$skills[$skillId];
                Log::warning("[SkillService] Skill {$skillId} não encontrada no Redis, usando fallback em memória.");
            } else {
                throw new \InvalidArgumentException("Skill {$skillId} not found");
            }
        }

        // garante que atributos do caster venham de stats
        $casterStats = $caster['stats'] ?? [];
        if (is_string($casterStats)) $casterStats = json_decode($casterStats, true);

        $isTickSkill = !empty($skill['tick_skill_flag']);
        if (!$isTickSkill) {
            // Cooldown global apenas para jogadores
            if ($casterType === 'character') {
                $this->checkCooldown($battleId, $casterId, $skill['post_delay'] ?? 0);
            }

            // Verifica stamina (leitura inicial)
            $currentStamina = $this->staminaService->getCurrentStamina($battleId, $casterId, $casterType);
            $requiredStamina = (int)($skill['stamina_cost'] ?? 0);
            if ($currentStamina < $requiredStamina) {
                throw new InsufficientStaminaException(
                    "Stamina insuficiente ({$currentStamina} / {$requiredStamina})"
                );
            }

            // Consome stamina (operação atômica via StaminaService)
            $currentAfterConsumption = $this->staminaService->consumeStamina(
                $battleId,
                $casterId,
                $requiredStamina,
                $casterType
            );

            if ($currentAfterConsumption === null) {
                throw new InsufficientStaminaException(
                    "Stamina insuficiente (race condition detectada ao tentar consumir)"
                );
            }
        }

        if (!$target) {
            throw new \InvalidArgumentException("Target is required for skill {$skill['name']}");
        }

        $targetKey = ($targetType ?? 'character') === 'monster' ? 'monsters' : 'characters_data';
        $redisKey = "battle:$battleId:$targetKey";
        $luaPath = storage_path("redis_scripts/battle_skill_indexed.lua"); // NOVO: novo script indexado
        $luaScript = file_get_contents($luaPath);

        // cálculo de power dependendo do type
        if (($skill['type'] ?? '') === 'physical' || ($skill['type'] ?? '') === 'magical') {
            $attackAttribute = ($skill['type'] === 'physical') ? 'strength' : 'intelligence';
            $baseAttack = $casterStats[$attackAttribute] ?? 0;
            $damage = ($skill['power'] ?? 0) + $baseAttack;
            $strength = match (($skill['level'] ?? 1)) {
                1 => 'weak',
                2 => 'medium',
                3 => 'strong',
                default => 'medium',
            };
            $power = $this->calculateDamage($damage, $strength);
        } elseif (($skill['type'] ?? '') === 'buff') {
            $power = $skill['power'] ?? 0;
        } elseif (($skill['type'] ?? '') === 'heal') {
            $power = ($skill['power'] ?? 0) + ($casterStats['intelligence'] ?? 0);
        } else {
            // fallback neutro
            $power = $skill['power'] ?? 0;
        }

        // recupera propriedades de stack do $skill (se existirem no model/array)
        $stackable = !empty($skill['stackable']) ? '1' : '0';
        $maxStacks = isset($skill['max_stacks']) ? (int)$skill['max_stacks'] : 1;
        $stackBehavior = $skill['stack_behavior'] ?? 'add';

        // executa script passando battleId e os novos parâmetros de stack
        $resultJson = Redis::eval(
            $luaScript,
            1,
            $redisKey,
            $skill['type'] ?? '',
            $casterId,
            $target['instanceId'],
            $power,
            $skill['stat'] ?? '',
            $skill['duration'],
            $skill['level'],
            $casterType,
            $skill['tick_skill_id'] ?? null,
            $skill['tick_interval'] ?? null,
            $battleId,          // ARGV[11]
            $stackable,         // ARGV[12]
            $maxStacks,         // ARGV[13]
            $stackBehavior      // ARGV[14]
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
            'current_stamina' => $currentAfterConsumption ?? null,
            'initial_stamina' => null,
            'used_stamina_total' => $usedStaminaTotal,
            'pre_delay' => $skill['pre_delay'] ?? 0,
            'post_delay' => $skill['post_delay'] ?? 0,
            'someoneDied' => $result['target_died'] ?? false,
            'target_hp' => $result['current_hp'] ?? null,
            'damage_dealt' => $result['damage_dealt'] ?? null,
            'healed_amount' => $result['healed_amount'] ?? null,
            'buff_applied' => $result['buff_applied'] ?? null,
            'debuff_applied' => $result['debuff_applied'] ?? null,
            'debuff_chance' => $result['debuff_chance'] ?? null,
            'debuff_roll' => $result['debuff_roll'] ?? null,
            'debuff_failed' => $result['debuff_failed'] ?? null,
        ];
    }

    /**
     * Checa e seta cooldown global simples
     */
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

    /**
     * Helper: busca a skill no Redis para um caster específico.
     *
     * NOVO: agora tentamos buscar primeiro em "tick_skills" (caso a skill seja uma tick)
     * e depois em "skills". Isso garante que tick skills (com tick_skill_flag = true)
     * sejam resgatadas da chave correta.
     *
     * @param string $battleId
     * @param string $casterType 'character'|'monster'
     * @param string $casterId
     * @param int $skillId
     * @return array|null
     */
    private function findSkillInRedis(string $battleId, string $casterType, string $casterId, int $skillId): ?array
    {
        // 📌 Chave NOVA: tick_skills por caster/instância
        $tickKey = "battle:{$battleId}:{$casterType}:{$casterId}:tick_skills";
        $rawTick = Redis::get($tickKey);
        if ($rawTick) {
            $arrTick = json_decode($rawTick, true);
            if (is_array($arrTick)) {
                foreach ($arrTick as $s) {
                    if ((int)($s['id'] ?? -1) === $skillId) {
                        // Encontrou na lista de tick skills -> retorna imediatamente
                        Log::debug("[SkillService] Skill {$skillId} encontrada em tick_skills ({$tickKey}) para {$casterType}:{$casterId}");
                        return $s;
                    }
                }
            } else {
                Log::warning("[SkillService] Dados inválidos em {$tickKey} para {$casterType}:{$casterId}");
            }
        }

        // Se não encontrou em tick_skills, busca nas skills normais (comportamento antigo)
        $redisKey = "battle:{$battleId}:{$casterType}:{$casterId}:skills";
        $raw = Redis::get($redisKey);
        if (!$raw) {
            Log::info("[SkillService] Nenhuma skill carregada no Redis para {$casterType}:{$casterId} (battle {$battleId})");
            return null;
        }

        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            Log::warning("[SkillService] Dados de skills inválidos no Redis para {$casterType}:{$casterId}");
            return null;
        }

        foreach ($arr as $s) {
            if ((int)($s['id'] ?? -1) === $skillId) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Versão estática do finder para métodos estáticos (usa Redis facade)
     *
     * NOVO: mesma lógica — procura primeiro em tick_skills, depois em skills.
     */
    private static function findSkillInRedisStatic(string $battleId, string $casterType, string $casterId, int $skillId): ?array
    {
        // tenta tick_skills primeiro
        $tickKey = "battle:{$battleId}:{$casterType}:{$casterId}:tick_skills";
        $rawTick = Redis::get($tickKey);
        if ($rawTick) {
            $arrTick = json_decode($rawTick, true);
            if (is_array($arrTick)) {
                foreach ($arrTick as $s) {
                    if ((int)($s['id'] ?? -1) === $skillId) {
                        return $s;
                    }
                }
            }
        }

        // fallback para skills
        $redisKey = "battle:{$battleId}:{$casterType}:{$casterId}:skills";
        $raw = Redis::get($redisKey);
        if (!$raw) {
            return null;
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return null;
        }
        foreach ($arr as $s) {
            if ((int)($s['id'] ?? -1) === $skillId) {
                return $s;
            }
        }
        return null;
    }
}
