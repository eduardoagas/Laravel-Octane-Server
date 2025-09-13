<?php

namespace App\Helpers\Battle;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * BattleSkillProcessor
 *
 * Recebe dados estruturados (arrays) em vez de argv do Lua.
 * Não usa closures; toda lógica é implementada como métodos.
 *
 * Método principal: processSkill(array $skill, array $casterEntity, array $targetEntity, string $battleId, string $casterType, string $targetType, array $options = [])
 *
 * Retorna array com o resultado (mesma estrutura conceitual do Lua -> 'damage_dealt','healed_amount','buff_applied', 'target_died', 'exec_time_ms', etc).
 */
class BattleSkillProcessor
{
    public function processSkill(
        array $skill,
        array $casterEntity,
        array $targetEntity,
        string $battleId,
        string $casterType, // 'character'|'monster'
        string $targetType, // 'character'|'monster'
        array $options = [] // extras como lock_time, parentSkillId, addEffects
    ): array {
        $tStart = microtime(true);
        $result = [];
        $someoneDied = false;

        $skillType = $skill['type'] ?? '';
        $casterId = (string)($casterEntity['instanceId'] ?? ($casterEntity['id'] ?? ''));
        $targetId = (string)($targetEntity['instanceId'] ?? ($targetEntity['id'] ?? ''));

        $power = $skill['power'] ?? 0;
        $stat = $skill['stat'] ?? '';
        $duration = $skill['duration'] ?? null;
        $level = (int)($skill['level'] ?? 1);
        $tickSkillId = $skill['tick_skill_id'] ?? null;
        $tickInterval = $skill['tick_interval'] ?? null;
        $stackableFlag = !empty($skill['stackable']);
        $maxStacks = isset($skill['max_stacks']) ? (int)$skill['max_stacks'] : 1;
        $stackBehavior = $skill['stack_behavior'] ?? 'refresh';
        $lockTime = $options['lock_time'] ?? ($skill['lock_time'] ?? 0);
        $parentSkillId = $options['parentSkillId'] ?? ($skill['id'] ?? null);
        $addEffects = $options['addEffects'] ?? ($skill['add_effects'] ?? []);

        // read target entity snapshot from battle hash (same format you already use)
        $targetHashKey = $this->getTargetHashKey($battleId, $targetType);
        $rawTarget = Redis::hget($targetHashKey, $targetId);
        if (!$rawTarget) {
            return ['error' => "Target not found in key {$targetHashKey} id={$targetId}"];
        }
        $entity = json_decode($rawTarget, true);
        $stats = isset($entity['stats']) ? $this->shallowCopy($entity['stats']) : [];

        // aplicamos buffs/debuffs snapshot (não persistimos aqui, apenas para cálculo)
        $this->applyEffectsFromHash($this->perInstanceBuffsKey($targetHashKey, $targetId), $stats);
        $this->applyEffectsFromHash($this->perInstanceDebuffsKey($targetHashKey, $targetId), $stats);

        // resolve caster entity if needed (we may be given the casterEntity already)
        $casterSnapshot = $casterEntity;
        if (!isset($casterSnapshot['stats']) || is_string($casterSnapshot['stats'])) {
            // try to resolve from battle structures if not provided
            $rawCaster = $this->findCasterRaw($battleId, $casterType, $casterId);
            if ($rawCaster) $casterSnapshot = $rawCaster;
        }
        $casterStats = $casterSnapshot['stats'] ?? [];

        // handle main skill types
        if ($skillType === 'physical' || $skillType === 'magical') {
            $damage = $this->computeBaseDamage($power, $casterStats, $skillType, $level, $skill);
            $element = $casterSnapshot['element'] ?? 'neutral';

            $elemental_potency = $this->getElementPotency($casterStats, $element);
            $elemental_resistance = $this->getElementResistance($stats, $element);

            $damage = max(0, ($damage * (1 + $elemental_potency / 100.0)));
            $defense = $this->getDamageDefense($stats, $skillType, $casterSnapshot);
            $damage = max(0, $damage - $defense);

            if ($elemental_resistance != 0) $damage = $damage * (1 - $elemental_resistance / 100.0);

            $resistanceStat = ($skillType === 'physical') ? 'physical_damage_resistance' : 'magical_damage_resistance';
            $resistance = floatval($stats[$resistanceStat] ?? 0);
            if ($resistance != 0) $damage = $damage * (1 - $resistance / 100.0);

            $damage = intval(floor($damage));
            $damage = max(0, $damage);

            [$newHp, $oldHp] = $this->applyHpDelta($battleId, $targetType, $targetId, -$damage, $stats);
            $stats['current_hp'] = $newHp;
            $result['damage_dealt'] = $damage;
            if ($newHp <= 0 && $oldHp > 0) $someoneDied = true;

            // debuff children
            $this->applyDebuffFlow($casterId, $casterType, $targetType, $targetId, $stat, $power, $duration, $level, $targetHashKey, $stats, $stackableFlag, $maxStacks, $stackBehavior, $tickSkillId, $tickInterval, $battleId, $result, $someoneDied);
        } elseif (in_array($skillType, ['physicalPercentageDamage', 'physicalPurePercentageDamage', 'magicalPercentageDamage', 'magicalPurePercentageDamage'])) {
            $maxHp = intval($stats['hp'] ?? 100);
            $currentHpShadow = intval($stats['current_hp'] ?? 0);
            if (strpos($skillType, 'Percentage') !== false && strpos($skillType, 'Pure') === false) {
                $damage = $currentHpShadow * ($power / 100.0);
            } else {
                // pure: percent of max
                $damage = $maxHp * ($power / 100.0);
            }

            $casterSnapshot = $casterSnapshot;
            $element = $casterSnapshot['element'] ?? 'neutral';
            $elemental_potency = $this->getElementPotency($casterSnapshot['stats'] ?? [], $element);

            $elemental_resistance = $this->getElementResistance($stats, $element);
            $effective_elemental_resistance = max(0, $elemental_resistance - $elemental_potency);
            if ($effective_elemental_resistance != 0) $damage = $damage * (1 - $effective_elemental_resistance / 100.0);

            $resistanceStat = (strpos($skillType, 'physical') !== false) ? 'physical_damage_resistance' : 'magical_damage_resistance';
            $resistance = intval($stats[$resistanceStat] ?? 0);
            $effective_resistance = max(0, $resistance - $elemental_potency);
            if ($effective_resistance != 0) $damage = $damage * (1 - $effective_resistance / 100.0);

            $damage = intval(floor(max(0, $damage)));
            [$newHp, $oldHp] = $this->applyHpDelta($battleId, $targetType, $targetId, -$damage, $stats);
            $stats['current_hp'] = $newHp;
            $result['damage_dealt'] = $damage;
            if ($newHp <= 0 && $oldHp > 0) $someoneDied = true;

            $this->applyDebuffFlow($casterId, $casterType, $targetType, $targetId, $stat, $power, $duration, $level, $targetHashKey, $stats, $stackableFlag, $maxStacks, $stackBehavior, $tickSkillId, $tickInterval, $battleId, $result, $someoneDied);
        } elseif ($skillType === 'heal') {
            $maxHp = intval($stats['hp'] ?? 100);
            $currentHpShadow = intval($stats['current_hp'] ?? 0);
            if ($currentHpShadow > 0) {
                $healPower = $this->computeBaseDamage($power, $casterStats, $skillType, $level, $skill);
                $potResHealPower = $this->calcEffectiveHeal($power, $casterStats, $stats);
                $effectiveHeal = min($potResHealPower, $maxHp - $currentHpShadow);
                if ($effectiveHeal > 0) {
                    [$newHp, $oldHp] = $this->applyHpDelta($battleId, $targetType, $targetId, $effectiveHeal, $stats);
                    $stats['current_hp'] = $newHp;
                }
                $result['healed_amount'] = $potResHealPower;
            }
        } elseif ($skillType === 'stamina') {
            // delegate stamina handling to StaminaService (user already does that). We'll just track used in delta.
            $result['stamina_delegated_to_php'] = true;
        } elseif ($skillType === 'revive') {
            $currentHpShadow = intval($stats['current_hp'] ?? 0);
            if ($currentHpShadow === 0) {
                $maxHp = intval($stats['hp'] ?? 100);
                $healPower = $this->calcEffectiveHeal($power, $casterStats, $stats);
                $effectiveHeal = min($healPower, $maxHp - $currentHpShadow);
                if ($effectiveHeal > 0) {
                    [$newHp, $oldHp] = $this->applyHpDelta($battleId, $targetType, $targetId, $effectiveHeal, $stats);
                    $stats['current_hp'] = $newHp;
                    $result['revive_applied'] = true;
                }
                $result['healed_amount'] = $healPower;
            }
        } elseif ($skillType === 'buff') {
            $this->applyBuffFlow($casterId, $casterType, $targetId, $stat, $power, $duration, $tickSkillId, $tickInterval, $parentSkillId, $addEffects, $targetHashKey, $stackableFlag, $maxStacks, $stackBehavior, $result);
        } elseif ($skillType === 'debuff') {
            $this->applyDebuffFlow($casterId, $casterType, $targetType, $targetId, $stat, $power, $duration, $level, $targetHashKey, $stats, $stackableFlag, $maxStacks, $stackBehavior, $tickSkillId, $tickInterval, $battleId, $result, $someoneDied);
        } else {
            return ['error' => "Unknown skill type: {$skillType}"];
        }

        // lock handling
        if ($lockTime > 0) {
            $lockKey = "skill_lock:{$battleId}:{$targetType}:{$targetId}";
            $currentLock = intval(Redis::get($lockKey) ?? 0);
            $now = intval(Redis::command('TIME')[0] ?? time());
            $lockSeconds = (int) ceil($lockTime / 1000.0);
            $newLock = $now + $lockSeconds;
            if ($newLock > $currentLock) {
                Redis::set($lockKey, $newLock);
                Redis::expire($lockKey, $lockSeconds * 2);
            }
        }

        // We DO NOT persist entity['stats']['current_hp'] anymore as single source-of-truth:
        // hp is maintained via delta keys. However we keep returning a 'current_hp' for immediate use.
        $result['target_died'] = $someoneDied;
        $result['current_hp'] = intval($stats['current_hp'] ?? 0);

        $t2 = microtime(true);
        $result['exec_time_ms'] = intval(($t2 - $tStart) * 1000);

        return $result;
    }

    // ---------------------------
    // Métodos auxiliares (sem closures)
    // ---------------------------
    protected function getTargetHashKey(string $battleId, string $targetType): string
    {
        $suffix = ($targetType === 'monster') ? 'monsters' : 'characters_data';
        return "battle:{$battleId}:{$suffix}";
    }

    protected function perInstanceBuffsKey(string $targetHashKey, string $instanceId): string
    {
        return "{$targetHashKey}:{$instanceId}:buffs";
    }

    protected function perInstanceDebuffsKey(string $targetHashKey, string $instanceId): string
    {
        return "{$targetHashKey}:{$instanceId}:debuffs";
    }

    protected function shallowCopy($tbl): array
    {
        if (!is_array($tbl)) return [];
        $copy = [];
        foreach ($tbl as $k => $v) $copy[$k] = $v;
        return $copy;
    }

    protected function applyEffectsFromHash(string $hashKey, array &$stats): void
    {
        $entries = Redis::hgetall($hashKey);
        if (empty($entries)) return;
        foreach ($entries as $json) {
            $buffData = json_decode($json, true);
            $statName = $buffData['stat'] ?? null;
            $powerVal = floatval($buffData['power'] ?? 0);
            if ($statName && $powerVal != 0) {
                $current = floatval($stats[$statName] ?? 0);
                $stats[$statName] = $current + $powerVal;
            }
        }
    }

    protected function findCasterRaw(string $battleId, string $casterType, string $casterId): ?array
    {
        $key = ($casterType === 'character' || $casterType === 'player') ? "battle:{$battleId}:characters_data" : "battle:{$battleId}:monsters";
        $raw = Redis::hget($key, (string)$casterId);
        if (!$raw) return null;
        return json_decode($raw, true);
    }

    protected function readStat(array $tbl, ...$keys)
    {
        foreach ($keys as $k) {
            if (isset($tbl[$k])) return floatval($tbl[$k]);
        }
        return null;
    }

    protected function getDefense(array $st, string $skillType)
    {
        if ($skillType === 'physical') return floatval($st['physical_defense'] ?? 0);
        return floatval($st['magical_defense'] ?? 0);
    }

    protected function getDamageDefense(array $st, string $skillType, ?array $casterEntity)
    {
        $defense = $this->getDefense($st, $skillType);
        $casterStats = $casterEntity['stats'] ?? [];
        $caster_luk = max(1, intval($this->readStat($casterStats, 'luck') ?? 0));
        // calc pierce
        list($min_pct, $max_pct) = $this->calcPierceRange($caster_luk);
        $pierce_pct = $this->randomPierce($min_pct, $max_pct, 4);
        $pierce_pct = floatval(number_format($pierce_pct, 2));
        $reduced_defense = $defense * (1 - $pierce_pct / 100.0);
        if ($reduced_defense < 0) $reduced_defense = 0;
        // store last natural_pierce info in logable place if necessary
        // Skipping storing in result to keep function pure; caller may log if wanted.
        return $reduced_defense;
    }

    protected function calcPierceRange(int $luk): array
    {
        $base = 1 + ($luk - 1) * (30 - 1) / (300 - 1);
        $max_pct = 30;
        $min_pct = $base;
        if ($luk >= 300) $min_pct = 28;
        return [$min_pct, $max_pct];
    }

    protected function randomPierce(float $min, float $max, float $bias): float
    {
        $r = $this->nanoRandom();
        $curved = pow($r, $bias);
        return $min + ($max - $min) * $curved;
    }

    protected function nanoRandom(): float
    {
        $t = Redis::command('TIME'); // [secs, micros]
        $secs = intval($t[0] ?? 0);
        $micros = intval($t[1] ?? 0);
        $incKey = "__rand_counter_" . rand(1, 1000000);
        $inc = intval(Redis::incr($incKey) ?? 0);
        if ($inc === 1) Redis::expire($incKey, 60);
        $seed = $secs * 1000000 + (($micros + $inc) % 1000000);
        mt_srand($seed);
        mt_rand();
        mt_rand();
        return mt_rand() / mt_getrandmax();
    }

    protected function getElementPotency(array $stats, string $element): float
    {
        if ($element === 'neutral') return floatval($stats['non_elemental_potency'] ?? 0);
        if ($element === 'poison') return floatval($stats['poison_element_potency'] ?? 0);
        return floatval($stats[$element . '_potency'] ?? 0);
    }

    protected function getElementResistance(array $stats, string $element): float
    {
        if ($element === 'neutral') return floatval($stats['non_elemental_resistance'] ?? 0);
        if ($element === 'poison') return floatval($stats['poison_element_resistance'] ?? 0);
        return floatval($stats[$element . '_resistance'] ?? 0);
    }

    protected function calcEffectiveHeal($power, array $casterStats, array $targetStats): int
    {
        $healingPotency = floatval($casterStats['healing_potency'] ?? 0);
        $recoverPotency = floatval($targetStats['recover_potency'] ?? 0);
        $heal = $power * (1 + $healingPotency / 100.0) * (1 + $recoverPotency / 100.0);
        return max(0, intval(floor($heal)));
    }

    protected function computeBaseDamage($skillPower, array $casterStats, string $skillType, int $skillLevel, array $skillMeta): float
    {
        // reuse SkillService.calculateDamage semantics (simplified)
        $dmgBoost = 1.25;
        $exponent = 0.45;
        $multipliers = ['weak' => 0.9, 'medium' => 1.0, 'strong' => 1.6];
        $strengthMapping = [1 => 'weak', 2 => 'medium', 3 => 'strong'];
        $mainAttr = ($skillType === 'physical') ? 'strength' : 'intelligence';
        $attrValue = (float)($casterStats[$mainAttr] ?? 0.0);
        $base = $skillPower + $attrValue;
        $strength = $strengthMapping[$skillLevel] ?? 'medium';
        $mult = $multipliers[$strength] ?? 1.0;
        $attrScale = pow(pow(1.03, 1 + $attrValue), $exponent);
        $damage = $base * $mult * $attrScale * $dmgBoost;
        return round($damage, 6);
    }

    /**
     * Aplica delta_hp no novo formato (lost, hp_max, dead)
     * $delta: positive = heal, negative = damage
     *
     * Retorna [newHp, oldHp]
     */
    protected function applyHpDelta(string $battleId, string $targetType, string $instanceId, int $delta, array $stats): array
    {
        $key = ($targetType === 'character')
            ? "battle:{$battleId}:character:{$instanceId}:delta_hp"
            : "battle:{$battleId}:monster:{$instanceId}:delta_hp";

        $raw = Redis::get($key);
        $obj = $raw ? json_decode($raw, true) : null;
        $hp_max = intval($stats['hp'] ?? 0);
        if (!$obj) {
            $obj = ['lost' => 0, 'hp_max' => $hp_max, 'dead' => false];
        } else {
            // keep hp_max up-to-date if changed
            $obj['hp_max'] = $hp_max;
        }
        $oldLost = intval($obj['lost']);
        $oldHp = $obj['hp_max'] - $oldLost;

        if ($delta < 0) {
            $damage = -intval($delta);
            $obj['lost'] = min($obj['hp_max'], $obj['lost'] + $damage);
        } elseif ($delta > 0) {
            $heal = intval($delta);
            $obj['lost'] = max(0, $obj['lost'] - $heal);
        }

        $obj['dead'] = ($obj['lost'] >= $obj['hp_max']);
        Redis::set($key, json_encode($obj, JSON_UNESCAPED_UNICODE));
        $newHp = $obj['hp_max'] - $obj['lost'];
        return [$newHp, $oldHp];
    }

    protected function applyBuffFlow($casterId, $casterType, $targetId, $stat, $power, $duration, $tickSkillId, $tickInterval, $parentSkillId, array $addEffects, $targetHashKey, $stackableFlag, $maxStacks, $stackBehavior, array &$result)
    {
        $buffsHashKey = $this->perInstanceBuffsKey($targetHashKey, $targetId);
        $buffIndex = $this->perInstanceBuffsKey($targetHashKey, $targetId) . '_index';
        $field = "{$targetId}:{$stat}:{$casterId}";
        $exists = Redis::hget($buffsHashKey, $field);
        $now = time();

        if ($exists) {
            $old = json_decode($exists, true);
            if ($stackableFlag) {
                $oldStacks = intval($old['stacks'] ?? 1);
                if ($stackBehavior === 'add') {
                    $old['stacks'] = min($maxStacks, $oldStacks + 1);
                    $old['power'] = intval(($old['power'] ?? 0)) + intval(floor($power));
                    $old['duration'] = $duration;
                    $old['applied_at'] = $now;
                } elseif ($stackBehavior === 'refresh') {
                    $old['duration'] = $duration;
                    $old['applied_at'] = $now;
                    if (intval(floor($power)) > intval($old['power'] ?? 0)) $old['power'] = intval(floor($power));
                } elseif ($stackBehavior === 'replace') {
                    $old = [
                        'caster_id' => $casterId,
                        'caster_type' => $casterType,
                        'stat' => $stat,
                        'power' => intval(floor($power)),
                        'duration' => $duration,
                        'applied_at' => $now,
                        'tick_skill_id' => $tickSkillId,
                        'tick_interval' => $tickInterval,
                        'stacks' => 1,
                        'max_stacks' => $maxStacks,
                        'stack_behavior' => $stackBehavior,
                        'parent_skill_id' => $parentSkillId
                    ];
                } else {
                    $old['stacks'] = min($maxStacks, $oldStacks + 1);
                    $old['power'] = intval(($old['power'] ?? 0)) + intval(floor($power));
                    $old['duration'] = $duration;
                    $old['applied_at'] = $now;
                }
                $old['parent_skill_id'] = $parentSkillId;
                Redis::hset($buffsHashKey, $field, json_encode($old, JSON_UNESCAPED_UNICODE));
                Redis::sadd($buffIndex, $field);
                $result['buff_applied'] = $old;
            } else {
                $old['duration'] = $duration;
                $old['applied_at'] = $now;
                if (intval(floor($power)) > intval($old['power'] ?? 0)) $old['power'] = intval(floor($power));
                $old['parent_skill_id'] = $parentSkillId;
                Redis::hset($buffsHashKey, $field, json_encode($old, JSON_UNESCAPED_UNICODE));
                $result['buff_applied'] = $old;
            }
        } else {
            $buff = [
                'caster_id' => $casterId,
                'caster_type' => $casterType,
                'stat' => $stat !== '' ? $stat : 'unknown',
                'power' => intval(floor($power)),
                'duration' => $duration !== null ? intval($duration) : null,
                'applied_at' => $now,
                'tick_skill_id' => $tickSkillId,
                'tick_interval' => $tickInterval,
                'stacks' => 1,
                'max_stacks' => $maxStacks,
                'stack_behavior' => $stackBehavior,
                'parent_skill_id' => $parentSkillId
            ];
            Redis::hset($buffsHashKey, $field, json_encode($buff, JSON_UNESCAPED_UNICODE));
            Redis::sadd($buffIndex, $field);
            $result['buff_applied'] = $buff;
        }

        // addEffects children
        if (!empty($addEffects) && is_array($addEffects)) {
            $result['buffs_applied_add_effects'] = [];
            foreach ($addEffects as $eff) {
                $effStat = $eff['stat'] ?? '';
                $effValue = intval($eff['value'] ?? 0);
                $effSkillId = $eff['skill_id'] ?? $parentSkillId;
                if ($effStat === '' || $effValue === 0) continue;
                $effField = "{$targetId}:{$effStat}:{$casterId}";
                $existing = Redis::hget($buffsHashKey, $effField);
                $newEffect = [
                    'caster_id' => $casterId,
                    'caster_type' => $casterType,
                    'stat' => $effStat,
                    'power' => intval(floor($effValue)),
                    'duration' => $duration !== null ? intval($duration) : null,
                    'applied_at' => time(),
                    'parent_skill_id' => $effSkillId,
                    'tick_skill_id' => $tickSkillId,
                    'tick_interval' => $tickInterval,
                    'stacks' => 1,
                    'max_stacks' => $maxStacks,
                    'stack_behavior' => $stackBehavior
                ];
                if ($existing) {
                    $old = json_decode($existing, true);
                    if ($stackableFlag) {
                        $oldStacks = intval($old['stacks'] ?? 1);
                        if ($stackBehavior === 'add') {
                            $old['stacks'] = min($maxStacks, $oldStacks + 1);
                            $old['power'] = intval(($old['power'] ?? 0)) + intval(floor($effValue));
                            $old['duration'] = $newEffect['duration'];
                            $old['applied_at'] = time();
                        } elseif ($stackBehavior === 'refresh') {
                            $old['duration'] = $newEffect['duration'];
                            $old['applied_at'] = time();
                            if (intval(floor($effValue)) > intval($old['power'] ?? 0)) $old['power'] = intval(floor($effValue));
                        } elseif ($stackBehavior === 'replace') {
                            $old = $newEffect;
                        } else {
                            $old['stacks'] = min($maxStacks, $oldStacks + 1);
                            $old['power'] = intval(($old['power'] ?? 0)) + intval(floor($effValue));
                            $old['duration'] = $newEffect['duration'];
                            $old['applied_at'] = time();
                        }
                        Redis::hset($buffsHashKey, $effField, json_encode($old, JSON_UNESCAPED_UNICODE));
                        Redis::sadd($buffIndex, $effField);
                        $result['buffs_applied_add_effects'][] = $old;
                    } else {
                        $old['duration'] = $newEffect['duration'];
                        $old['applied_at'] = time();
                        if (intval(floor($effValue)) > intval($old['power'] ?? 0)) $old['power'] = intval(floor($effValue));
                        Redis::hset($buffsHashKey, $effField, json_encode($old, JSON_UNESCAPED_UNICODE));
                        $result['buffs_applied_add_effects'][] = $old;
                    }
                } else {
                    Redis::hset($buffsHashKey, $effField, json_encode($newEffect, JSON_UNESCAPED_UNICODE));
                    Redis::sadd($buffIndex, $effField);
                    $result['buffs_applied_add_effects'][] = $newEffect;
                }
            }
        }
    }

    protected function applyDebuffFlow($casterId, $casterType, $targetType, $targetId, $stat, $power, $duration, $level, $targetHashKey, array &$stats, $stackableFlag, $maxStacks, $stackBehavior, $tickSkillId, $tickInterval, $battleId, array &$result, &$someoneDied)
    {
        $debuffsHashKey = $this->perInstanceDebuffsKey($targetHashKey, $targetId);
        $debuffIndex = $this->perInstanceDebuffsKey($targetHashKey, $targetId) . '_index';

        if ($stat === null || $stat === '') return;

        $casterEntity = $this->findCasterRaw($battleId, $casterType, $casterId);
        $casterStats = $casterEntity['stats'] ?? [];
        $caster_luk = max(1, intval($this->readStat($casterStats, 'luck') ?? 0));
        $target_vit = max(1, intval($this->readStat($stats, 'vitality', 'vit') ?? 0));

        $debuff_strength = 'weak';
        if ($level == 2) $debuff_strength = 'medium';
        if ($level == 3) $debuff_strength = 'strong';
        $stat_chance = $caster_luk / ($caster_luk + $target_vit);
        $base_chances = ['weak' => 0.10, 'medium' => 0.20, 'strong' => 0.50];
        $min_chances = ['weak' => 0.00, 'medium' => 0.01, 'strong' => 0.10];
        $chance = $base_chances[$debuff_strength] * $stat_chance;

        $resistance_value = intval($stats['nstatus_resistance'] ?? 0);
        $resistance_key = $stat . '_resistance';
        $resistance_value += intval($stats[$resistance_key] ?? 0);
        $resistance_value = min($resistance_value, 100);
        $chance = $chance * (1 - $resistance_value / 100.0);
        $chance = max($min_chances[$debuff_strength], min(0.99, $chance));

        $roll = $this->nanoRandom();
        if ($roll < $chance) {
            $durationLocal = $duration ? intval($duration) : null;
            $field = "{$targetId}:{$stat}:{$casterId}";
            $exists = Redis::hget($debuffsHashKey, $field);
            if ($exists) {
                $old = json_decode($exists, true);
                if ($stackableFlag) {
                    $oldStacks = intval($old['stacks'] ?? 1);
                    if ($stackBehavior === 'add') {
                        $old['stacks'] = min($maxStacks, $oldStacks + 1);
                        $old['power'] = intval(($old['power'] ?? 0)) + intval(floor($power));
                        $old['duration'] = $durationLocal;
                        $old['applied_at'] = time();
                    } elseif ($stackBehavior === 'refresh') {
                        $old['duration'] = $durationLocal;
                        $old['applied_at'] = time();
                        if (intval(floor($power)) > intval($old['power'] ?? 0)) $old['power'] = intval(floor($power));
                    } elseif ($stackBehavior === 'replace') {
                        $old = [
                            'caster_id' => $casterId,
                            'caster_type' => $casterType,
                            'stat' => $stat,
                            'power' => intval(floor($power)),
                            'duration' => $durationLocal,
                            'applied_at' => time(),
                            'tick_skill_id' => $tickSkillId,
                            'tick_interval' => $tickInterval,
                            'stacks' => 1,
                            'max_stacks' => $maxStacks,
                            'stack_behavior' => $stackBehavior
                        ];
                    } else {
                        $old['power'] = intval(($old['power'] ?? 0)) + intval(floor($power));
                        $old['stacks'] = min($maxStacks, $oldStacks + 1);
                        $old['duration'] = $durationLocal;
                        $old['applied_at'] = time();
                    }
                    Redis::hset($debuffsHashKey, $field, json_encode($old, JSON_UNESCAPED_UNICODE));
                    Redis::sadd($debuffIndex, $field);
                    $result['debuff_applied'] = $old;
                } else {
                    $old['duration'] = $durationLocal;
                    $old['applied_at'] = time();
                    if (intval(floor($power)) > intval($old['power'] ?? 0)) $old['power'] = intval(floor($power));
                    Redis::hset($debuffsHashKey, $field, json_encode($old, JSON_UNESCAPED_UNICODE));
                    $result['debuff_applied'] = $old;
                }
            } else {
                $debuff = [
                    'caster_id' => $casterId,
                    'caster_type' => $casterType,
                    'stat' => $stat,
                    'power' => intval(floor($power)),
                    'duration' => $durationLocal,
                    'applied_at' => time(),
                    'tick_skill_id' => $tickSkillId,
                    'tick_interval' => $tickInterval,
                    'stacks' => 1,
                    'max_stacks' => $maxStacks,
                    'stack_behavior' => $stackBehavior
                ];
                Redis::hset($debuffsHashKey, $field, json_encode($debuff, JSON_UNESCAPED_UNICODE));
                Redis::sadd($debuffIndex, $field);
                if ($debuff['stat'] === 'death' && intval($stats['hp'] ?? 0) > 0) {
                    [$newHp, $oldHp] = $this->applyHpDelta($battleId, $targetType, $targetId, -999999, $stats);
                    $stats['current_hp'] = $newHp;
                    $someoneDied = true;
                }
                $result['debuff_applied'] = $debuff;
            }
            $result['debuff_chance'] = $chance;
            $result['debuff_roll'] = $roll;
        } else {
            $result['debuff_applied'] = null;
            $result['debuff_failed'] = true;
            $result['debuff_chance'] = $chance;
            $result['debuff_roll'] = $roll;
        }
    }
}
