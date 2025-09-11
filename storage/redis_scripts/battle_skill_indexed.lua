-- storage/redis_scripts/battle_skill_indexed.lua
-- Versão index-based com suporte a stackable buffs/debuffs
-- Reutilizável tanto para "skills" quanto para "items/consumables".
-- PER-INSTANCE: escreve/ler buffs/debuffs em <targetKey>:<instanceId>:buffs|debuffs
-- KEYS[1] = target hash (ex: battle:<id>:characters_data OR battle:<id>:monsters)
-- ARGV:
--  1 = skillType / effect_type (ex: "physical","magical","heal","stamina","buff","debuff","revive","percentageDamage", etc.)
--  2 = casterId
--  3 = targetId (instanceId)
--  4 = power (damage / heal / effect_value)
--  5 = stat (stat afetado para buff/debuff, ou "" se não aplicável)
--  6 = duration (em segundos ou nil)
--  7 = level (opcional)
--  8 = casterType ("character"|"monster")
--  9 = tickSkillId (opcional)
-- 10 = tickInterval (opcional)
-- 11 = battleId
-- 12 = stackable ("1" ou "0")
-- 13 = max_stacks
-- 14 = stack_behavior ("add"|"refresh"|"replace")
-- 15 = lock_time (em milissegundos, opcional)
local targetKey = KEYS[1]
local skillType = ARGV[1] or ""
local casterId = ARGV[2] or ""
local targetId = ARGV[3] or ""
local power = tonumber(ARGV[4]) or 0
local stat = ARGV[5] or ""
local duration = tonumber(ARGV[6]) or nil
local level = tonumber(ARGV[7]) or 1
local casterType = ARGV[8] or ""
local tickSkillId = tonumber(ARGV[9]) or nil
local tickInterval = tonumber(ARGV[10]) or nil
local battleId = ARGV[11] or ""
local stackableFlag = (ARGV[12] == "1") and true or false
local maxStacks = tonumber(ARGV[13]) or 1
local stackBehavior = ARGV[14] or "refresh"

local lockTime = tonumber(ARGV[15]) or 0

local t_start = redis.call("TIME")

-- inferir targetType a partir do targetKey (usado para locks e nomes legíveis)
local targetType = "generic"
if string.find(targetKey, "characters_data") then
    targetType = "character"
elseif string.find(targetKey, "monsters") then
    targetType = "monster"
end

-- read target entity directly from provided targetKey (NO FALLBACK)
local raw = redis.call("HGET", targetKey, targetId)
if not raw then
    return cjson.encode({
        error = "Target not found in key " .. tostring(targetKey)
    })
end

local entity = cjson.decode(raw)

local function shallow_copy(tbl)
    local copy = {}
    for k, v in pairs(tbl) do
        copy[k] = v
    end
    return copy
end

local stats = shallow_copy(entity["stats"] or {})

local someoneDied = false
local result = {}

-- PER-INSTANCE keys (usando targetKey como base)
local debuffsHashKey = targetKey .. ":" .. tostring(targetId) .. ":debuffs"
local buffsHashKey = targetKey .. ":" .. tostring(targetId) .. ":buffs"
local debuffIndexInstance = targetKey .. ":" .. tostring(targetId) .. ":debuff_index"
local buffIndexInstance = targetKey .. ":" .. tostring(targetId) .. ":buff_index"

-- função auxiliar para aplicar deltas (buffs/debuffs)
local function apply_effects(hashKey, statsTable)
    local entries = redis.call("HGETALL", hashKey)
    for i = 1, #entries, 2 do
        local buffData = cjson.decode(entries[i + 1])
        local statName = buffData["stat"]
        local power = tonumber(buffData["power"] or 0)
        if statName and power ~= 0 then
            local current = tonumber(statsTable[statName] or 0)
            statsTable[statName] = current + power
        end
    end
end

-- aplica buffs e debuffs sobre os stats originais
apply_effects(buffsHashKey, stats)
apply_effects(debuffsHashKey, stats)

local _rand_counter_key = targetKey .. ":" .. targetId .. ":rand_counter"
local function nano_random()
    local t = redis.call("TIME")
    local secs = tonumber(t[1]) or 0
    local micros = tonumber(t[2]) or 0
    local inc = tonumber(redis.call("INCR", _rand_counter_key) or 0)
    if inc == 1 then
        redis.call("EXPIRE", _rand_counter_key, 60)
    end
    local seed = secs * 1000000 + ((micros + inc) % 1000000)
    math.randomseed(seed)
    math.random();
    math.random()

    return math.random()
end

local function read_stat(tbl, ...)
    for i = 1, select('#', ...) do
        local k = select(i, ...)
        if tbl[k] ~= nil then
            return tonumber(tbl[k])
        end
    end
    return nil
end

local function get_defense(stats_table, skillType)
    if skillType == "physical" then
        return tonumber(stats_table["physical_defense"] or 0)
    else
        return tonumber(stats_table["magical_defense"] or 0)
    end
end

-- calcula defesa efetiva para dano (com natural_pierce aplicado)
local function get_damage_defense(stats_table, skillType, casterEntity)
    local defense = get_defense(stats_table, skillType)
    local casterStats = casterEntity and casterEntity["stats"] or {}
    local caster_luk = math.max(1, tonumber(read_stat(casterStats, "luck")) or 0)

    -- funções auxiliares
    local function calc_pierce_range(luk)
        local base = 1 + (luk - 1) * (30 - 1) / (300 - 1)
        local max_pct = 30
        local min_pct = base
        if luk >= 300 then
            min_pct = 28 -- não fixa, mas funil no topo
        end
        return min_pct, max_pct
    end

    local function random_pierce(min_pct, max_pct, bias)
        local r = nano_random() -- usa nosso RNG com seed
        local curved = r ^ bias
        return min_pct + (max_pct - min_pct) * curved
    end

    local min_pct, max_pct = calc_pierce_range(caster_luk)
    local pierce_pct = random_pierce(min_pct, max_pct, 4) -- bias=4
    pierce_pct = tonumber(string.format("%.2f", pierce_pct))

    local reduced_defense = defense * (1 - pierce_pct / 100)
    if reduced_defense < 0 then
        reduced_defense = 0
    end

    -- log para debug
    result["natural_pierce"] = {
        applied = true,
        pierce_pct = pierce_pct,
        caster_luk = caster_luk
    }

    return reduced_defense
end

-- helper: update atômico de HP (usando targetKey como base)
local function apply_hp_delta(targetKey, battleId, targetId, stats, delta)
    local hpKey = targetKey .. ":" .. tostring(targetId) .. ":hp"
    local currentHp = redis.call("GET", hpKey)

    if not currentHp then
        currentHp = tonumber(stats["current_hp"] or 0)
        redis.call("SET", hpKey, currentHp)
    else
        currentHp = tonumber(currentHp)
    end

    local newHp = currentHp + delta
    if newHp < 0 then
        newHp = 0
    end
    redis.call("SET", hpKey, newHp)

    return newHp, currentHp
end

-- helper: update atômico de STAMINA (usando targetKey como base)
local function apply_stamina_delta(targetKey, battleId, targetId, stats, delta)
    local stKey = targetKey .. ":" .. tostring(targetId) .. ":stamina"
    local currentSt = redis.call("GET", stKey)

    if not currentSt then
        currentSt = tonumber(stats["stamina"] or 0)
        redis.call("SET", stKey, currentSt)
    else
        currentSt = tonumber(currentSt)
    end

    local newSt = currentSt + delta
    if newSt < 0 then
        newSt = 0
    end
    redis.call("SET", stKey, newSt)

    return newSt, currentSt
end

-- resolve caster explicitly (no scanning)
local function findCasterExplicit(cId, cType)
    if cId == nil or cId == "" then
        return nil
    end
    if not battleId or battleId == "" then
        return nil
    end

    local casterKey
    if cType == "character" or cType == "player" then
        casterKey = "battle:" .. battleId .. ":characters_data"
    else
        casterKey = "battle:" .. battleId .. ":monsters"
    end

    local rawCaster = redis.call("HGET", casterKey, tostring(cId))
    if not rawCaster then
        return nil
    end
    return cjson.decode(rawCaster)
end

-- apply_debuff (per-instance)
local function apply_debuff(casterId, casterType, targetId, stat, power, duration, level)
    if stat == nil or stat == "" then
        return
    end

    local casterEntity = findCasterExplicit(casterId, casterType)
    local casterStats = casterEntity and casterEntity["stats"] or {}
    local caster_luk = math.max(1, tonumber(read_stat(casterStats, "luck")) or 0)
    local target_vit = math.max(1, tonumber(read_stat(stats, "vitality", "vit")) or 0)

    local debuff_strength
    if level == 2 then
        debuff_strength = "medium"
    elseif level == 3 then
        debuff_strength = "strong"
    else
        debuff_strength = "weak"
    end

    local stat_chance = caster_luk / (caster_luk + target_vit)
    local base_chances = {
        weak = 0.10,
        medium = 0.20,
        strong = 0.50
    }
    local min_chances = {
        weak = 0.00,
        medium = 0.01,
        strong = 0.10
    }

    local chance = base_chances[debuff_strength] * stat_chance
    chance = math.max(min_chances[debuff_strength], math.min(0.99, chance))

    local roll = nano_random()
    if roll < chance then
        duration = duration and math.floor(duration) or nil
        local field = tostring(targetId) .. ":" .. stat .. ":" .. tostring(casterId)
        local exists = redis.call("HGET", debuffsHashKey, field)
        if exists then
            local old = cjson.decode(exists)
            if stackableFlag then
                local oldStacks = tonumber(old["stacks"] or 1)
                if stackBehavior == "add" then
                    old["stacks"] = math.min(maxStacks, oldStacks + 1)
                    old["power"] = (old["power"] or 0) + math.floor(power)
                    old["duration"] = duration
                    old["applied_at"] = redis.call("TIME")[1]
                elseif stackBehavior == "refresh" then
                    old["duration"] = duration
                    old["applied_at"] = redis.call("TIME")[1]
                    if math.floor(power) > (old["power"] or 0) then
                        old["power"] = math.floor(power)
                    end
                elseif stackBehavior == "replace" then
                    old = {
                        caster_id = casterId,
                        caster_type = casterType,
                        stat = stat,
                        power = math.floor(power),
                        duration = duration,
                        applied_at = redis.call("TIME")[1],
                        tick_skill_id = tickSkillId,
                        tick_interval = tickInterval,
                        stacks = 1,
                        max_stacks = maxStacks,
                        stack_behavior = stackBehavior
                    }
                else
                    old["power"] = (old["power"] or 0) + math.floor(power)
                    old["stacks"] = math.min(maxStacks, oldStacks + 1)
                    old["duration"] = duration
                    old["applied_at"] = redis.call("TIME")[1]
                end
                redis.call("HSET", debuffsHashKey, field, cjson.encode(old))
                redis.call("SADD", debuffIndexInstance, field)
                result["debuff_applied"] = old
            else
                old["duration"] = duration
                old["applied_at"] = redis.call("TIME")[1]
                if math.floor(power) > (old["power"] or 0) then
                    old["power"] = math.floor(power)
                end
                redis.call("HSET", debuffsHashKey, field, cjson.encode(old))
                result["debuff_applied"] = old
            end
        else
            local debuff = {
                caster_id = casterId,
                caster_type = casterType,
                stat = stat,
                power = math.floor(power),
                duration = duration,
                applied_at = redis.call("TIME")[1],
                tick_skill_id = tickSkillId,
                tick_interval = tickInterval,
                stacks = 1,
                max_stacks = maxStacks,
                stack_behavior = stackBehavior
            }
            redis.call("HSET", debuffsHashKey, field, cjson.encode(debuff))
            redis.call("SADD", debuffIndexInstance, field)
            if debuff["stat"] == "death" and tonumber(stats["current_hp"] or 0) > 0 then
                apply_hp_delta(targetKey, battleId, targetId, stats, -999999)
                stats["current_hp"] = 0
                someoneDied = true
            end
            result["debuff_applied"] = debuff
        end

        result["debuff_chance"] = chance
        result["debuff_roll"] = roll
    else
        result["debuff_applied"] = nil
        result["debuff_failed"] = true
        result["debuff_chance"] = chance
        result["debuff_roll"] = roll
    end
end

-- apply_buff (per-instance)
local function apply_buff(casterId, casterType, targetId, stat, power, duration)
    if stat == nil or stat == "" then
        return
    end
    local field = tostring(targetId) .. ":" .. stat .. ":" .. tostring(casterId)
    local exists = redis.call("HGET", buffsHashKey, field)
    if exists then
        local old = cjson.decode(exists)
        if stackableFlag then
            local oldStacks = tonumber(old["stacks"] or 1)
            if stackBehavior == "add" then
                old["stacks"] = math.min(maxStacks, oldStacks + 1)
                old["power"] = (old["power"] or 0) + math.floor(power)
                old["duration"] = duration
                old["applied_at"] = redis.call("TIME")[1]
            elseif stackBehavior == "refresh" then
                old["duration"] = duration
                old["applied_at"] = redis.call("TIME")[1]
                if math.floor(power) > (old["power"] or 0) then
                    old["power"] = math.floor(power)
                end
            elseif stackBehavior == "replace" then
                old = {
                    caster_id = casterId,
                    caster_type = casterType,
                    stat = stat,
                    power = math.floor(power),
                    duration = duration,
                    applied_at = redis.call("TIME")[1],
                    tick_skill_id = tickSkillId,
                    tick_interval = tickInterval,
                    stacks = 1,
                    max_stacks = maxStacks,
                    stack_behavior = stackBehavior
                }
            else
                old["stacks"] = math.min(maxStacks, oldStacks + 1)
                old["power"] = (old["power"] or 0) + math.floor(power)
                old["duration"] = duration
                old["applied_at"] = redis.call("TIME")[1]
            end
            redis.call("HSET", buffsHashKey, field, cjson.encode(old))
            redis.call("SADD", buffIndexInstance, field)
            result["buff_applied"] = old
        else
            old["duration"] = duration
            old["applied_at"] = redis.call("TIME")[1]
            if math.floor(power) > (old["power"] or 0) then
                old["power"] = math.floor(power)
            end
            redis.call("HSET", buffsHashKey, field, cjson.encode(old))
            result["buff_applied"] = old
        end
    else
        local buff = {
            caster_id = casterId,
            caster_type = casterType,
            stat = stat ~= "" and stat or "unknown",
            power = math.floor(power),
            duration = duration and math.floor(duration) or nil,
            applied_at = redis.call("TIME")[1],
            tick_skill_id = tickSkillId,
            tick_interval = tickInterval,
            stacks = 1,
            max_stacks = maxStacks,
            stack_behavior = stackBehavior
        }
        redis.call("HSET", buffsHashKey, field, cjson.encode(buff))
        redis.call("SADD", buffIndexInstance, field)
        result["buff_applied"] = buff
    end
end

-- === Funções auxiliares para elementos ===
local function get_element_potency(statsTable, element)
    if element == "neutral" then
        return tonumber(statsTable["non_elemental_potency"] or 0)
    elseif element == "poison" then
        return tonumber(statsTable["poison_element_potency"] or 0)
    else
        return tonumber(statsTable[element .. "_potency"] or 0)
    end
end

local function get_element_resistance(statsTable, element)
    if element == "neutral" then
        return tonumber(statsTable["non_elemental_resistance"] or 0)
    elseif element == "poison" then
        return tonumber(statsTable["poison_element_resistance"] or 0)
    else
        return tonumber(statsTable[element .. "_resistance"] or 0)
    end
end

-- helper para calcular heal com potências
local function calc_effective_heal(power, casterStats, targetStats)
    local healingPotency = tonumber(casterStats["healing_potency"] or 0)
    local recoverPotency = tonumber(targetStats["recover_potency"] or 0)
    local heal = power * (1 + healingPotency / 100) * (1 + recoverPotency / 100)
    return math.max(0, math.floor(heal))
end

-- === Skill/Item handling ===
-- Note: 'skillType' covers both skill types and item effect types.
if skillType == "physical" or skillType == "magical" then
    local casterEntity = findCasterExplicit(casterId, casterType)
    local casterStats = casterEntity and casterEntity["stats"] or {}
    local element = casterEntity and casterEntity["element"] or "neutral" -- opcional: pode vir da skill
    local currentHpShadow = tonumber(stats["current_hp"] or 0)
    local damage = math.max(0, power)

    local elemental_potency = get_element_potency(casterStats, element)
    local elemental_resistance = get_element_resistance(stats, element)

     -- aplica potency primeiro
    damage = math.max(0, (damage * (1 + elemental_potency / 100)))

    -- aplica defesa
    local defense = get_damage_defense(stats, skillType, casterEntity)
    damage = math.max(0, damage - defense)

    -- aplica resistance (negativa aumenta dano)
    if elemental_resistance ~= 0 then
        damage = damage * (1 - elemental_resistance / 100)
    end

    -- aplica resistência final (positiva reduz, negativa aumenta)
    local resistanceStat = skillType == "physical" and "physical_damage_resistance" or "magical_damage_resistance"
    local resistance = tonumber(stats[resistanceStat] or 0) -- esperado em porcentagem (ex: 20 ou -30)
    if resistance ~= 0 then
        damage = damage * (1 - resistance / 100)
    end

    damage = math.floor(damage)
    -- garante que não fique negativo
    if damage < 0 then damage = 0 end


    local newHp, oldHp = apply_hp_delta(targetKey, battleId, targetId, stats, -damage)
    stats["current_hp"] = newHp
    result["damage_dealt"] = damage
    if newHp <= 0 and oldHp > 0 then
        someoneDied = true
    end

    apply_debuff(casterId, casterType, targetId, stat, power, duration, level)

elseif skillType == "physicalPercentageDamage" 
    or skillType == "physicalPurePercentageDamage"
    or skillType == "magicalPercentageDamage"
    or skillType == "magicalPurePercentageDamage" then

    local maxHp = tonumber(stats["hp"] or 100)
    local currentHpShadow = tonumber(stats["current_hp"] or 0)
    local damage = 0

    if skillType == "physicalPercentageDamage" or skillType == "magicalPercentageDamage" then
        damage = currentHpShadow * (power / 100)
    else -- pure
        damage = maxHp * (power / 100)
    end

    -- pega caster explicitamente
    local casterEntity = findCasterExplicit(casterId, casterType)
    local casterStats = casterEntity and casterEntity["stats"] or {}
    local element = casterEntity and casterEntity["element"] or "neutral"
    local elemental_potency = get_element_potency(casterStats, element)

    -- elemental resistance
    local elemental_resistance = get_element_resistance(stats, element)
    local effective_elemental_resistance = math.max(0, elemental_resistance - elemental_potency)
    if effective_elemental_resistance ~= 0 then
        damage = damage * (1 - effective_elemental_resistance / 100)
    end

    -- resistência final: física ou mágica
    local resistanceStat = (skillType:find("physical") and "physical_damage_resistance") or "magical_damage_resistance"
    local resistance = tonumber(stats[resistanceStat] or 0)
    local effective_resistance = math.max(0, resistance - elemental_potency)
    if effective_resistance ~= 0 then
        damage = damage * (1 - effective_resistance / 100)
    end

    damage = math.floor(math.max(0, damage))

    local newHp, oldHp = apply_hp_delta(targetKey, battleId, targetId, stats, -damage)
    stats["current_hp"] = newHp
    result["damage_dealt"] = damage
    if newHp <= 0 and oldHp > 0 then
        someoneDied = true
    end

    apply_debuff(casterId, casterType, targetId, stat, power, duration, level)



-- === Skill/Item handling ===
elseif skillType == "heal" then
    local maxHp = tonumber(stats["hp"] or 100)
    local currentHpShadow = tonumber(stats["current_hp"] or 0)
    if currentHpShadow > 0 then
        local casterEntity = findCasterExplicit(casterId, casterType)
        local casterStats = casterEntity and casterEntity["stats"] or {}
        local healPower = calc_effective_heal(power, casterStats, stats)

        local effectiveHeal = math.min(healPower, maxHp - currentHpShadow)
        if effectiveHeal > 0 then
            local newHp, oldHp = apply_hp_delta(targetKey, battleId, targetId, stats, effectiveHeal)
            stats["current_hp"] = newHp
        end
        result["healed_amount"] = healPower
    end

elseif skillType == "stamina" then
    -- Delegado ao PHP: não tocar stamina aqui para evitar duplicação.
    -- PHP deve chamar consume_stamina.lua ou StaminaService::consumeStamina após este script.
    result["stamina_delegated_to_php"] = true
    result["stamina_note"] = "handled_by_php"
    -- não alteramos stats["stamina"] aqui

elseif skillType == "revive" then
    local currentHpShadow = tonumber(stats["current_hp"] or 0)
    if currentHpShadow == 0 then
        local maxHp = tonumber(stats["hp"] or 100)
        local casterEntity = findCasterExplicit(casterId, casterType)
        local casterStats = casterEntity and casterEntity["stats"] or {}
        local healPower = calc_effective_heal(power, casterStats, stats)

        local effectiveHeal = math.min(healPower, maxHp - currentHpShadow)
        if effectiveHeal > 0 then
            local newHp, oldHp = apply_hp_delta(targetKey, battleId, targetId, stats, effectiveHeal)
            stats["current_hp"] = newHp
            result["revive_applied"] = true
        end
        result["healed_amount"] = healPower
    end
    
elseif skillType == "buff" then
    apply_buff(casterId, casterType, targetId, stat, power, duration)

elseif skillType == "debuff" then
    apply_debuff(casterId, casterType, targetId, stat, power, duration, level)

else
    return cjson.encode({
        error = "Unknown skill/item type: " .. tostring(skillType)
    })
end

-- === Lock handling ===
if lockTime > 0 then
    local lockKey = "skill_lock:" .. battleId .. ":" .. targetType .. ":" .. targetId
    local currentLock = tonumber(redis.call("GET", lockKey) or 0)
    local t = redis.call("TIME")
    local now = tonumber(t[1])
    local lockSeconds = math.ceil(lockTime / 1000)
    local newLock = now + lockSeconds
    if newLock > currentLock then
        redis.call("SET", lockKey, newLock)
        redis.call("EXPIRE", lockKey, lockSeconds * 2)
    end
end

-- só atualiza HP e stamina no entity, nunca mexe nos outros stats base
if stats["current_hp"] ~= nil then
    entity["stats"]["current_hp"] = stats["current_hp"]
end

if stats["stamina"] ~= nil then
    entity["stats"]["current_stamina"] = stats["current_stamina"]
end

redis.call("HSET", targetKey, targetId, cjson.encode(entity))

result["target_died"] = someoneDied
result["current_hp"] = math.floor(stats["current_hp"] or 0)
-- garante que current_stamina esteja presente sempre (se não definido, tenta ler do stats)
if stats["current_stamina"] ~= nil then
    entity["stats"]["current_stamina"] = math.floor(stats["current_stamina"])
end

local t2 = redis.call("TIME")
local exec_ms = (t2[1] - t_start[1]) * 1000 + (t2[2] - t_start[2]) / 1000
result["exec_time_ms"] = exec_ms

return cjson.encode(result)
