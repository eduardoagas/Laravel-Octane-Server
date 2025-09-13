<?php

namespace App\Services\Battle;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\SkillCooldownException;
use App\Exceptions\InsufficientStaminaException;
use App\Helpers\Battle\BattleSkillProcessor;

class SkillService
{
    private StaminaService $staminaService;
    private BattleSkillProcessor $processor;

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

    private string $luaScript;
    private ?string $luaSha = null;

    public function __construct()
    {
        $this->staminaService = new StaminaService();
        $this->processor = new BattleSkillProcessor();
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

        // aplica buffs/debuffs ativos do caster
        $casterStats = $this->applyCasterBuffs($battleId, $casterType, $casterId, $casterStats);

        if (is_string($casterStats)) $casterStats = json_decode($casterStats, true);

        $isTickSkill = !empty($skill['tick_skill_flag']);
        if (!$isTickSkill) {
            // Cooldown global apenas para jogadores
            if ($casterType === 'character') {
                //$this->checkCooldown($battleId, $casterId, $skill['post_delay'] ?? 0);
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
            )['current_after'];

            if ($currentAfterConsumption === null) {
                throw new InsufficientStaminaException(
                    "Stamina insuficiente (race condition detectada ao tentar consumir)"
                );
            }
        }

        if (!$target) {
            throw new \InvalidArgumentException("Target is required for skill {$skill['name']}");
        }

        // targetKey base (usado como KEYS[1] pelo script)
        $targetKey = ($targetType ?? 'character') === 'monster' ? 'monsters' : 'characters_data';
        $redisKey = "battle:$battleId:$targetKey";




        $targetTypeNormalized = ($targetType ?? 'character') === 'monster' ? 'monster' : 'character';

        // caster e target devem ser arrays com 'instanceId' e 'stats' (SkillService já tem estes)
        $casterEntity = [
            'instanceId' => (string)$casterId,
            'stats' => $caster['stats'] ?? $caster['stats'] ?? []
        ];

        $targetEntity = $target; // assume já tem 'instanceId' e 'stats' (se não, busque via Redis similar ao antigo)

        // Opcional: passe add_effects ou parentSkillId via options
        $options = [
            'lock_time' => $skill['lock_time'] ?? null,
            'parentSkillId' => $skill['id'] ?? null,
            'addEffects' => $skill['add_effects'] ?? []
        ];

        $start = microtime(true);
        $result = $this->processor->processSkill($skill, $casterEntity, $targetEntity, $battleId, $casterType, $targetTypeNormalized, $options);
        $elapsed = (microtime(true) - $start) * 1000.0;
        Log::info("[SkillService][PHPProcessor] php_exec_ms=" . round($elapsed, 2) . " battle={$battleId} caster={$casterType}:{$casterId} target={$target['instanceId']} skill={$skillId}");

        // Caso o script não tenha retornado current_hp, leia do hpKey (fonte da verdade)
        $targetHp = null;
        if (isset($result['current_hp'])) {
            $targetHp = (int)$result['current_hp'];
        } else {
            // hpKey padronizado: targetKey + ":" + targetId + ":hp"
            $hpKey = "battle:{$battleId}:{$targetKey}:" . $target['instanceId'] . ":hp";
            $hpRaw = Redis::get($hpKey);
            if ($hpRaw !== null) {
                $targetHp = (int)$hpRaw;
            }
        }

        // stamina read (mantive seu código)
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
            'target_hp' => $targetHp,
            'damage_dealt' => $result['damage_dealt'] ?? null,
            'healed_amount' => $result['healed_amount'] ?? null,
            'buff_applied' => $result['buff_applied'] ?? null,
            'debuff_applied' => $result['debuff_applied'] ?? null,
            'debuff_chance' => $result['debuff_chance'] ?? null,
            'debuff_roll' => $result['debuff_roll'] ?? null,
            'debuff_failed' => $result['debuff_failed'] ?? null,

        ];
    }


    public function startSkillCast(
        array $caster,
        ?array $target,
        string $battleId,
        int $skillId,
        string $casterType,
        string $targetType
    ): void {
        $casterId = (string)$caster['instanceId'];

        // Skill
        $skill = $this->findSkillInRedis($battleId, $casterType, $casterId, $skillId)
            ?? self::$skills[$skillId] ?? null;

        if (!$skill) throw new \InvalidArgumentException("Skill {$skillId} not found");

        // Stats e buffs
        $casterStats = $caster['stats'] ?? [];
        if (is_string($casterStats)) $casterStats = json_decode($casterStats, true);
        $casterStats = $this->applyCasterBuffs($battleId, $casterType, $casterId, $casterStats);

        // Stamina
        $requiredStamina = (int)($skill['stamina_cost'] ?? 0);
        $currentStamina = $this->staminaService->getCurrentStamina($battleId, $casterId, $casterType);
        if ($currentStamina < $requiredStamina) throw new InsufficientStaminaException();

        if (!$target) throw new \InvalidArgumentException("Target is required for skill {$skill['name']}");

        // Evento
        // dentro de startSkillCast antes de criar $eventId
        $totalMs = ($skill['pre_delay'] ?? 0) + ($skill['animation_time'] ?? 0) + ($skill['post_delay'] ?? 0) + ($skill['lock_time'] ?? 0);
        $ttlSec = max(1, (int)ceil($totalMs / 1000) + 1); // +1s buffer

        // tenta setar NX: se já existe, não enfileira outra skill
        // SOMENTE setar para characters (não para monsters)
        if ($casterType === 'character') {
            $executionKey = "battle:{$battleId}:skill_in_execution:{$casterId}";
            // tenta setar NX: se já existe, não enfileira outra skill
            $ok = Redis::set($executionKey, 1, 'NX', 'EX', $ttlSec);
            if (!$ok) {
                Log::channel('battle_debug')->debug("[startSkillCast] Caster {$casterId} já tem skill em execução, ignorando enfileiramento.");
                return; // ou lance exceção/retorne false conforme seu fluxo
            }
        }
        $eventId = uniqid('', true); // ID único do evento
        $event = [
            'caster_id' => $casterId,
            'caster_type' => $casterType,
            'skill_id' => $skillId,
            'target_id' => $target['instanceId'],
            'target_type' => $targetType,
            'phase' => 'pre_delay',
            'ready_at' => microtime(true) + (($skill['pre_delay'] ?? 0) / 1000),
            'pre_delay' => $skill['pre_delay'] ?? 0,
            'animation_time' => $skill['animation_time'] ?? 0,
            'post_delay' => $skill['post_delay'] ?? 0,
            'lock_time' => $skill['lock_time'] ?? 0,
        ];

        // Chaves Redis
        $zsetKey = "battle:{$battleId}:pending_skills_zset";   // ZSET com score = ready_at
        $hashKey = "battle:{$battleId}:pending_skills_data";   // HASH com eventId => JSON

        // Grava
        Redis::hset($hashKey, $eventId, json_encode($event));
        Redis::zadd($zsetKey, [$eventId => $event['ready_at']]);

        Log::info("EVENT CREATED: {$eventId} => " . json_encode($event));
    }


    /**
     * Checa e seta cooldown global simples
     */
    private function checkCooldown(string $battleId, string $casterId, int $postDelay)
    {
        $redisKey = "global_cooldown_at:{$battleId}:{$casterId}";
        $now = microtime(true);

        $ttlMs = $postDelay; // postDelay já em ms

        // Tenta setar a chave apenas se não existir (NX) e com TTL em ms
        $set = Redis::set($redisKey, $now + ($ttlMs / 1000), 'NX', 'PX', $ttlMs);

        if (!$set) {
            // se não conseguiu setar, a skill está em cooldown
            $readyAt = (float)(Redis::get($redisKey) ?? 0);
            throw new SkillCooldownException(
                "Skill em cooldown até " . date('H:i:s', (int)$readyAt)
            );
        }
    }



    /**
     * Calcula damage per hit (power) --- NÃO usa DEX.
     *
     * Parâmetros calibrados que reproduzem a última tabela:
     *  - exponent = 0.45
     *  - dmgBoost = 1.25
     *
     * signature preservada: calculateDamage($skillPower, $casterStats, $skillType, $skillLevel)
     */
    private function calculateDamage(float $skillPower, array $casterStats, string $skillType = 'physical', int $skillLevel = 1): float
    {
        // parâmetros calibrados (ajuste se quiser)
        $dmgBoost = 1.25;    // boost geral (mantido das últimas tabelas)
        $exponent = 0.45;    // expoente que controla escala por STR/INT

        // skill multipliers (mantive valores razoáveis)
        $multipliers = [
            'weak' => 0.9,
            'medium' => 1.0,
            'strong' => 1.6,
        ];
        $strengthMapping = [1 => 'weak', 2 => 'medium', 3 => 'strong'];

        // atributo principal (STR para físico, INT para mágico)
        $mainAttr = ($skillType === 'physical') ? 'strength' : 'intelligence';
        $attrValue = (float)($casterStats[$mainAttr] ?? 0.0);

        // base
        $base = $skillPower + $attrValue;
        $strength = $strengthMapping[$skillLevel] ?? 'medium';
        $mult = $multipliers[$strength] ?? 1.0;

        // escala exponencial suave (sem DEX)
        // attrScale = (1.02 ^ attr) ^ exponent
        $attrScale = pow(pow(1.03, 1 + $attrValue), $exponent);

        $damage = $base * $mult * $attrScale * $dmgBoost;

        return round($damage, 6);
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
     * Aplica todos os buffs/debuffs ativos em um array de stats (somente memória).
     * 
     * @param string $battleId
     * @param string $casterType 'character'|'monster'
     * @param string $casterId
     * @param array $stats
     * @return array stats atualizados com buffs aplicados
     */
    private function applyCasterBuffs(string $battleId, string $casterType, string $casterId, array $stats): array
    {
        $buffsKey   = "battle:{$battleId}:{$casterType}:{$casterId}:buffs";
        $debuffsKey = "battle:{$battleId}:{$casterType}:{$casterId}:debuffs";

        // copia os stats originais para não mexer no base
        $effective = $stats;

        $applyEffects = function (string $hashKey, array &$statsArr) {
            $entries = Redis::hgetall($hashKey);
            foreach ($entries as $json) {
                $data = json_decode($json, true);
                if (!isset($data['stat'], $data['power'])) continue;

                $statName = $data['stat'];
                $bonus    = (int)($data['power'] ?? 0);

                // aplica sobre o snapshot (não persiste em lugar nenhum)
                $statsArr[$statName] = ($statsArr[$statName] ?? 0) + $bonus;
            }

            // 🔹 Lida com resistances extras se existirem
            if (!empty($data['add_effects']) && is_array($data['add_effects'])) {
                foreach ($data['add_effects'] as $effect => $value) {
                    $statsArr[$effect] = ($statsArr[$effect] ?? 0) + (float)$value;
                }
            }
        };

        $applyEffects($buffsKey, $effective);
        $applyEffects($debuffsKey, $effective);

        // retorna o snapshot final, que pode ser serializado em JSON e enviado
        return $effective;
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
