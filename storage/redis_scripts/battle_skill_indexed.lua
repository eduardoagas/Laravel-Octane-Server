-- storage/redis_scripts/battle_skill_indexed_optimized.lua
-- Versão otimizada do battle_skill_indexed.lua
-- Mantém a lógica original, mas:
--  - evita HGETALL (usa index sets quando possível)
--  - cache local
--  - batch para addEffects
--  - reduz chamadas TIME/INCR
-- KEYS[1] = target hash (ex: battle:<id>:characters_data OR battle:<id>:monsters)
-- ARGV:
--  1 = skillType
--  2 = casterId
--  3 = targetId (instanceId)
--  4 = power
--  5 = stat
--  6 = duration
--  7 = level
--  8 = casterType
--  9 = tickSkillId
-- 10 = tickInterval
-- 11 = battleId
-- 12 = stackable ("1"|"0")
-- 13 = max_stacks
-- 14 = stack_behavior
-- 15 = lock_time
-- 16 = parentSkillId (optional)
-- 17 = addEffectsJson (optional)

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
local parentSkillId = ARGV[16] or nil
local addEffectsJson = ARGV[17] or nil

-- start time
local t_start = redis.call("TIME")
local t_start_secs = tonumber(t_start[1]) or 0
local t_start_micros = tonumber(t_start[2]) or 0

-- inferir targetType
local targetType = "generic"
if string.find(targetKey, "characters_data") then
    targetType = "character"
elseif string.find(targetKey, "monsters") then
    targetType = "monster"
end

-- helper: safe HGET + decode JSON
local function hget_json(key, field)
    local raw = redis.call("HGET", key, field)
    if not raw then return nil end
    local ok, dec = pcall(cjson.decode, raw)
    if ok and type(dec) == "table" then return dec end
    return nil
end

-- read target entity (cache)
local raw = redis.call("HGET", targetKey, targetId)
if not raw then
    return cjson.encode({ error = "Target not found in key " .. tostring(targetKey) })
end
local ok_entity, entity = pcall(cjson.decode, raw)
if not ok_entity or type(entity) ~= "table" then
    return cjson.encode({ error = "Target data corrupted for " .. tostring(targetId) })
end

-- shallow copy helper
local function shallow_copy(tbl)
    local copy = {}
    for k, v in pairs(tbl) do copy[k] = v end
    return copy
end

-- stats snapshot (cache local)
local stats = shallow_copy(entity["stats"] or {})

local someoneDied = false
local result = {}

-- PER-INSTANCE keys (index sets expected to exist when writing effects)
local debuffsHashKey = targetKey .. ":" .. tostring(targetId) .. ":debuffs"
local buffsHashKey = targetKey .. ":" .. tostring(targetId) .. ":buffs"
local debuffIndexInstance = targetKey .. ":" .. tostring(targetId) .. ":debuff_index"
local buffIndexInstance = targetKey .. ":" .. tostring(targetId) .. ":buff_index"

-- RNG: seed once per script using start time + ids to reduce INCR calls.
-- We'll use a simple deterministic seed combining time and ids to get varied randomness.
local function nano_random_factory()
    local seed_val = t_start_secs * 1000000 + t_start_micros
    -- mix with caster and target numeric parts if possible
    local function tonum(s) return tonumber(s) or 0 end
    seed_val = seed_val + tonum(casterId) * 1337 + tonum(targetId) * 31337
    math.randomseed(seed_val % 2147483647)
    -- warm up
    math.random(); math.random()
    local counter = 0
    return function()
        counter = counter + 1
        -- small perturbation
        return math.random()
    end
end
local nano_random = nano_random_factory()

-- helper to read prioritized stat fields like original read_stat
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

-- cached caster entity and stats (read once)
local function findCasterExplicitCached(cId, cType)
    if cId == nil or cId == "" or not battleId or battleId == "" then return nil end
    local casterKey
    if cType == "character" or cType == "player" then
        casterKey = "battle:" .. battleId .. ":characters_data"
    else
        casterKey = "battle:" .. battleId .. ":monsters"
    end
    -- single HGET
    local rawCaster = redis.call("HGET", casterKey, tostring(cId))
    if not rawCaster then return nil end
    local ok, dec = pcall(cjson.decode, rawCaster)
    if ok and type(dec) == "table" then return dec end
    return nil
end

-- apply_effects: use index set if exists, fallback to HKEYS (safer than HGETALL)
local function apply_effects_from_index(hashKey, indexKey, statsTable)
    -- try SMEMBERS index (expected smaller and maintained on writes)
    local members = {}
    local ok, sm = pcall(function() return redis.call("SMEMBERS", indexKey) end)
    if ok and sm and #sm > 0 then
        members = sm
    else
        -- fallback: HKEYS (safer than HGETALL because returns only fields)
        local hkeys_ok, hkeys = pcall(function() return redis.call("HKEYS", hashKey) end)
        if hkeys_ok and hkeys then
            members = hkeys
        else
            members = {}
        end
    end

    for i = 1, #members do
        local field = members[i]
        local rawVal = redis.call("HGET", hashKey, field)
        if rawVal then
            local ok2, entry = pcall(cjson.decode, rawVal)
            if ok2 and type(entry) == "table" then
                local statName = entry["stat"]
                local powerVal = tonumber(entry["power"] or 0)
                if statName and powerVal ~= 0 then
                    local current = tonumber(statsTable[statName] or 0)
                    statsTable[statName] = current + powerVal
                end
            end
        end
    end
end

-- apply buffs and debuffs onto snapshot using index so we avoid big HGETALL
apply_effects_from_index(buffsHashKey, buffIndexInstance, stats)
apply_effects_from_index(debuffsHashKey, debuffIndexInstance, stats)

-- helpers for damage defense with cached casterEntity support
local function get_damage_defense(stats_table, skillType, casterEntity)
    local defense = get_defense(stats_table, skillType)
    local casterStats = casterEntity and casterEntity["stats"] or {}
    local caster_luk = math.max(1, tonumber(read_stat(casterStats, "luck")) or 0)

    local function calc_pierce_range(luk)
        local base = 1 + (luk - 1) * (30 - 1) / (300 - 1)
        local max_pct = 30
        local min_pct = base
        if luk >= 300 then min_pct = 28 end
        return min_pct, max_pct
    end

    local function random_pierce(min_pct, max_pct, bias)
        local r = nano_random()
        local curved = r ^ bias
        return min_pct + (max_pct - min_pct) * curved
    end

    local min_pct, max_pct = calc_pierce_range(caster_luk)
    local pierce_pct = random_pierce(min_pct, max_pct, 4)
    pierce_pct = tonumber(string.format("%.2f", pierce_pct))

    local reduced_defense = defense * (1 - pierce_pct / 100)
    if reduced_defense < 0 then reduced_defense = 0 end

    result["natural_pierce"] = { applied = true, pierce_pct = pierce_pct, caster_luk = caster_luk }
    return reduced_defense
end

-- hp/stamina helpers (kept semantics)
local function apply_hp_delta(targetKey, battleId, targetId, statsTable, delta)
    local hpKey = targetKey .. ":" .. tostring(targetId) .. ":hp"
    local currentHp = redis.call("GET", hpKey)
    if not currentHp then
        currentHp = tonumber(statsTable["current_hp"] or 0)
        redis.call("SET", hpKey, currentHp)
    else
        currentHp = tonumber(currentHp)
    end
    local newHp = currentHp + delta
    if newHp < 0 then newHp = 0 end
    redis.call("SET", hpKey, newHp)
    return newHp, currentHp
end

local function apply_stamina_delta(targetKey, battleId, targetId, statsTable, delta)
    local stKey = targetKey .. ":" .. tostring(targetId) .. ":stamina"
    local currentSt = redis.call("GET", stKey)
    if not currentSt then
        currentSt = tonumber(statsTable["stamina"] or 0)
        redis.call("SET", stKey, currentSt)
    else
        currentSt = tonumber(currentSt)
    end
    local newSt = currentSt + delta
    if newSt < 0 then newSt = 0 end
    redis.call("SET", stKey, newSt)
    return newSt, currentSt
end

-- apply_debuff: rewritten to use HGET per field and batched HSET/SADD where possible,
-- but preserving full original logic & result fields.
local function apply_debuff(casterId_local, casterType_local, targetId_local, stat_local, power_local, duration_local, level_local)
    if stat_local == nil or stat_local == "" then return end

    local casterEntity = findCasterExplicitCached(casterId_local, casterType_local)
    local casterStats = casterEntity and casterEntity["stats"] or {}
    local caster_luk = math.max(1, tonumber(read_stat(casterStats, "luck")) or 0)
    local target_vit = math.max(1, tonumber(read_stat(stats, "vitality", "vit")) or 0)

    local debuff_strength
    if level_local == 2 then
        debuff_strength = "medium"
    elseif level_local == 3 then
        debuff_strength = "strong"
    else
        debuff_strength = "weak"
    end

    local stat_chance = caster_luk / (caster_luk + target_vit)
    local base_chances = { weak = 0.10, medium = 0.20, strong = 0.50 }
    local min_chances = { weak = 0.00, medium = 0.01, strong = 0.10 }
    local chance = base_chances[debuff_strength] * stat_chance

    local resistance_value = tonumber(stats["nstatus_resistance"]) or 0
    local resistance_key = stat_local .. "_resistance"
    resistance_value = resistance_value + (tonumber(stats[resistance_key]) or 0)
    resistance_value = math.min(resistance_value, 100)
    chance = chance * (1 - resistance_value / 100)

    chance = math.max(min_chances[debuff_strength], math.min(0.99, chance))

    local roll = nano_random()
    if roll < chance then
        duration_local = duration_local and math.floor(duration_local) or nil
        local field = tostring(targetId_local) .. ":" .. stat_local .. ":" .. tostring(casterId_local)
        local existsRaw = redis.call("HGET", debuffsHashKey, field)
        if existsRaw then
            local ok_e, old = pcall(cjson.decode, existsRaw)
            if not ok_e then old = {} end
            if stackableFlag then
                local oldStacks = tonumber(old["stacks"] or 1)
                if stackBehavior == "add" then
                    old["stacks"] = math.min(maxStacks, oldStacks + 1)
                    old["power"] = (old["power"] or 0) + math.floor(power_local)
                    old["duration"] = duration_local
                    old["applied_at"] = t_start_secs
                elseif stackBehavior == "refresh" then
                    old["duration"] = duration_local
                    old["applied_at"] = t_start_secs
                    if math.floor(power_local) > (old["power"] or 0) then old["power"] = math.floor(power_local) end
                elseif stackBehavior == "replace" then
                    old = {
                        caster_id = casterId_local, caster_type = casterType_local, stat = stat_local,
                        power = math.floor(power_local), duration = duration_local,
                        applied_at = t_start_secs, tick_skill_id = tickSkillId,
                        tick_interval = tickInterval, stacks = 1, max_stacks = maxStacks,
                        stack_behavior = stackBehavior
                    }
                else
                    old["power"] = (old["power"] or 0) + math.floor(power_local)
                    old["stacks"] = math.min(maxStacks, oldStacks + 1)
                    old["duration"] = duration_local
                    old["applied_at"] = t_start_secs
                end
                old["parent_skill_id"] = parentSkillId
                redis.call("HSET", debuffsHashKey, field, cjson.encode(old))
                redis.call("SADD", debuffIndexInstance, field)
                result["debuff_applied"] = old
            else
                old["duration"] = duration_local
                old["applied_at"] = t_start_secs
                if math.floor(power_local) > (old["power"] or 0) then old["power"] = math.floor(power_local) end
                old["parent_skill_id"] = parentSkillId
                redis.call("HSET", debuffsHashKey, field, cjson.encode(old))
                result["debuff_applied"] = old
            end
        else
            local debuff = {
                caster_id = casterId_local, caster_type = casterType_local, stat = stat_local,
                power = math.floor(power_local), duration = duration_local,
                applied_at = t_start_secs, tick_skill_id = tickSkillId,
                tick_interval = tickInterval, stacks = 1, max_stacks = maxStacks,
                stack_behavior = stackBehavior, parent_skill_id = parentSkillId
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

-- apply_buff: similar approach, and batch child addEffects processing
local function apply_buff(casterId_local, casterType_local, targetId_local, stat_local, power_local, duration_local)
    if stat_local == nil or stat_local == "" then return end
    local field = tostring(targetId_local) .. ":" .. stat_local .. ":" .. tostring(casterId_local)
    local existsRaw = redis.call("HGET", buffsHashKey, field)
    local now = t_start_secs

    if existsRaw then
        local ok_e, old = pcall(cjson.decode, existsRaw)
        if not ok_e then old = {} end
        if stackableFlag then
            local oldStacks = tonumber(old["stacks"] or 1)
            if stackBehavior == "add" then
                old["stacks"] = math.min(maxStacks, oldStacks + 1)
                old["power"] = (old["power"] or 0) + math.floor(power_local)
                old["duration"] = duration_local
                old["applied_at"] = now
            elseif stackBehavior == "refresh" then
                old["duration"] = duration_local
                old["applied_at"] = now
                if math.floor(power_local) > (old["power"] or 0) then old["power"] = math.floor(power_local) end
            elseif stackBehavior == "replace" then
                old = {
                    caster_id = casterId_local, caster_type = casterType_local,
                    stat = stat_local, power = math.floor(power_local),
                    duration = duration_local, applied_at = now,
                    tick_skill_id = tickSkillId, tick_interval = tickInterval,
                    stacks = 1, max_stacks = maxStacks,
                    stack_behavior = stackBehavior,
                    parent_skill_id = parentSkillId
                }
            else
                old["stacks"] = math.min(maxStacks, oldStacks + 1)
                old["power"] = (old["power"] or 0) + math.floor(power_local)
                old["duration"] = duration_local
                old["applied_at"] = now
            end
            old["parent_skill_id"] = parentSkillId
            redis.call("HSET", buffsHashKey, field, cjson.encode(old))
            redis.call("SADD", buffIndexInstance, field)
            result["buff_applied"] = old
        else
            old["duration"] = duration_local
            old["applied_at"] = now
            if math.floor(power_local) > (old["power"] or 0) then old["power"] = math.floor(power_local) end
            old["parent_skill_id"] = parentSkillId
            redis.call("HSET", buffsHashKey, field, cjson.encode(old))
            result["buff_applied"] = old
        end
    else
        local buff = {
            caster_id = casterId_local, caster_type = casterType_local,
            stat = stat_local ~= "" and stat_local or "unknown",
            power = math.floor(power_local),
            duration = duration_local and math.floor(duration_local) or nil,
            applied_at = now,
            tick_skill_id = tickSkillId,
            tick_interval = tickInterval,
            stacks = 1,
            max_stacks = maxStacks,
            stack_behavior = stackBehavior,
            parent_skill_id = parentSkillId
        }
        redis.call("HSET", buffsHashKey, field, cjson.encode(buff))
        redis.call("SADD", buffIndexInstance, field)
        result["buff_applied"] = buff
    end

    -- Process addEffectsJson in batches (to avoid giant single execution)
    if addEffectsJson then
        local okae, addEffects = pcall(function() return cjson.decode(addEffectsJson) end)
        if okae and type(addEffects) == "table" and #addEffects > 0 then
            result["buffs_applied_add_effects"] = result["buffs_applied_add_effects"] or {}
            -- batch size: tuneable; keep small to reduce single-op time
            local BATCH = 8
            local total = #addEffects
            for b = 1, total, BATCH do
                local end_i = math.min(b + BATCH - 1, total)
                -- For each effect in this batch, perform HGET + HSET as needed
                for i = b, end_i do
                    local effect = addEffects[i]
                    if effect then
                        local effStat = effect["stat"] or ""
                        local effValue = tonumber(effect["value"] or 0)
                        local effSkillId = effect["skill_id"] or parentSkillId
                        if effStat ~= "" and effValue ~= 0 then
                            local effField = tostring(targetId) .. ":" .. effStat .. ":" .. tostring(casterId)
                            local existingRaw = redis.call("HGET", buffsHashKey, effField)
                            local newEffect = {
                                caster_id = casterId,
                                caster_type = casterType,
                                stat = effStat,
                                power = math.floor(effValue),
                                duration = duration and math.floor(duration) or nil,
                                applied_at = t_start_secs,
                                parent_skill_id = effSkillId,
                                tick_skill_id = tickSkillId,
                                tick_interval = tickInterval,
                                stacks = 1,
                                max_stacks = maxStacks,
                                stack_behavior = stackBehavior
                            }
                            if existingRaw then
                                local okx, old = pcall(cjson.decode, existingRaw)
                                if not okx then old = {} end
                                if stackableFlag then
                                    local oldStacks = tonumber(old["stacks"] or 1)
                                    if stackBehavior == "add" then
                                        old["stacks"] = math.min(maxStacks, oldStacks + 1)
                                        old["power"] = (old["power"] or 0) + math.floor(effValue)
                                        old["duration"] = newEffect.duration
                                        old["applied_at"] = t_start_secs
                                    elseif stackBehavior == "refresh" then
                                        old["duration"] = newEffect.duration
                                        old["applied_at"] = t_start_secs
                                        if math.floor(effValue) > (old["power"] or 0) then old["power"] = math.floor(effValue) end
                                    elseif stackBehavior == "replace" then
                                        old = newEffect
                                    else
                                        old["stacks"] = math.min(maxStacks, oldStacks + 1)
                                        old["power"] = (old["power"] or 0) + math.floor(effValue)
                                        old["duration"] = newEffect.duration
                                        old["applied_at"] = t_start_secs
                                    end
                                    redis.call("HSET", buffsHashKey, effField, cjson.encode(old))
                                    redis.call("SADD", buffIndexInstance, effField)
                                    table.insert(result["buffs_applied_add_effects"], old)
                                else
                                    old["duration"] = newEffect.duration
                                    old["applied_at"] = t_start_secs
                                    if math.floor(effValue) > (old["power"] or 0) then old["power"] = math.floor(effValue) end
                                    redis.call("HSET", buffsHashKey, effField, cjson.encode(old))
                                    table.insert(result["buffs_applied_add_effects"], old)
                                end
                            else
                                redis.call("HSET", buffsHashKey, effField, cjson.encode(newEffect))
                                redis.call("SADD", buffIndexInstance, effField)
                                table.insert(result["buffs_applied_add_effects"], newEffect)
                            end
                        end
                    end
                end
                -- small pause is not possible here but batching reduces single EVAL time
            end
        end
    end
end

-- process addEffects for debuff similarly (batched)
if addEffectsJson and skillType == "debuff" then
    local ok, addEffects = pcall(function() return cjson.decode(addEffectsJson) end)
    if ok and type(addEffects) == "table" and #addEffects > 0 then
        result["debuffs_applied_add_effects"] = result["debuffs_applied_add_effects"] or {}
        local BATCH = 8
        local total = #addEffects
        for b = 1, total, BATCH do
            local end_i = math.min(b + BATCH - 1, total)
            for i = b, end_i do
                local effect = addEffects[i]
                if effect then
                    local effStat = effect["stat"] or ""
                    local effValue = tonumber(effect["value"] or 0)
                    local effSkillId = effect["skill_id"] or parentSkillId
                    if effStat ~= "" and effValue ~= 0 then
                        local effField = tostring(targetId) .. ":" .. effStat .. ":" .. tostring(casterId)
                        local existingRaw = redis.call("HGET", debuffsHashKey, effField)
                        local newEffect = {
                            caster_id = casterId,
                            caster_type = casterType,
                            stat = effStat,
                            power = math.floor(effValue),
                            duration = duration and math.floor(duration) or nil,
                            applied_at = t_start_secs,
                            parent_skill_id = effSkillId,
                            tick_skill_id = tickSkillId,
                            tick_interval = tickInterval,
                            stacks = 1,
                            max_stacks = maxStacks,
                            stack_behavior = stackBehavior
                        }
                        if existingRaw then
                            local okx, old = pcall(cjson.decode, existingRaw)
                            if not okx then old = {} end
                            if stackableFlag then
                                local oldStacks = tonumber(old["stacks"] or 1)
                                if stackBehavior == "add" then
                                    old["stacks"] = math.min(maxStacks, oldStacks + 1)
                                    old["power"] = (old["power"] or 0) + math.floor(effValue)
                                    old["duration"] = newEffect.duration
                                    old["applied_at"] = t_start_secs
                                elseif stackBehavior == "refresh" then
                                    old["duration"] = newEffect.duration
                                    old["applied_at"] = t_start_secs
                                    if math.floor(effValue) > (old["power"] or 0) then old["power"] = math.floor(effValue) end
                                elseif stackBehavior == "replace" then
                                    old = newEffect
                                else
                                    old["stacks"] = math.min(maxStacks, oldStacks + 1)
                                    old["power"] = (old["power"] or 0) + math.floor(effValue)
                                    old["duration"] = newEffect.duration
                                    old["applied_at"] = t_start_secs
                                end
                                redis.call("HSET", debuffsHashKey, effField, cjson.encode(old))
                                redis.call("SADD", debuffIndexInstance, effField)
                                table.insert(result["debuffs_applied_add_effects"], old)
                            else
                                old["duration"] = newEffect.duration
                                old["applied_at"] = t_start_secs
                                if math.floor(effValue) > (old["power"] or 0) then old["power"] = math.floor(effValue) end
                                redis.call("HSET", debuffsHashKey, effField, cjson.encode(old))
                                table.insert(result["debuffs_applied_add_effects"], old)
                            end
                        else
                            redis.call("HSET", debuffsHashKey, effField, cjson.encode(newEffect))
                            redis.call("SADD", debuffIndexInstance, effField)
                            table.insert(result["debuffs_applied_add_effects"], newEffect)

                            if effStat == "death" and tonumber(stats["current_hp"] or 0) > 0 then
                                apply_hp_delta(targetKey, battleId, targetId, stats, -999999)
                                stats["current_hp"] = 0
                                someoneDied = true
                            end
                        end
                    end
                end
            end
        end
    end
end

-- MAIN: skill handling (optimized order and cached lookups)
if skillType == "physical" or skillType == "magical" then
    local casterEntity = findCasterExplicitCached(casterId, casterType)
    local casterStats = casterEntity and casterEntity["stats"] or {}
    local element = casterEntity and casterEntity["element"] or "neutral"
    local currentHpShadow = tonumber(stats["current_hp"] or 0)
    local damage = math.max(0, power)

    local elemental_potency = (casterEntity and tonumber(casterStats["elemental_potency"] or casterStats["potency"] or 0)) or 0
    local elemental_resistance = (tonumber(stats["elemental_resistance"] or 0)) or get_element_resistance and get_element_resistance(stats, element) or 0

    -- apply potency adjustments
    damage = math.max(0, (damage * (1 + elemental_potency / 100)))

    local defense = get_damage_defense(stats, skillType, casterEntity)
    damage = math.max(0, damage - defense)

    if elemental_resistance ~= 0 then
        damage = damage * (1 - elemental_resistance / 100)
    end

    local resistanceStat = skillType == "physical" and "physical_damage_resistance" or "magical_damage_resistance"
    local resistance = tonumber(stats[resistanceStat] or 0)
    if resistance ~= 0 then
        damage = damage * (1 - resistance / 100)
    end

    damage = math.floor(damage)
    if damage < 0 then damage = 0 end

    local newHp, oldHp = apply_hp_delta(targetKey, battleId, targetId, stats, -damage)
    stats["current_hp"] = newHp
    result["damage_dealt"] = damage
    if newHp <= 0 and oldHp > 0 then someoneDied = true end

    -- apply debuff as original
    apply_debuff(casterId, casterType, targetId, stat, power, duration, level)

elseif skillType == "physicalPercentageDamage" or skillType == "physicalPurePercentageDamage"
    or skillType == "magicalPercentageDamage" or skillType == "magicalPurePercentageDamage" then

    local maxHp = tonumber(stats["hp"] or 100)
    local currentHpShadow = tonumber(stats["current_hp"] or 0)
    local damage = 0

    if skillType == "physicalPercentageDamage" or skillType == "magicalPercentageDamage" then
        damage = currentHpShadow * (power / 100)
    else
        damage = maxHp * (power / 100)
    end

    local casterEntity = findCasterExplicitCached(casterId, casterType)
    local casterStats = casterEntity and casterEntity["stats"] or {}
    local element = casterEntity and casterEntity["element"] or "neutral"
    local elemental_potency = (casterEntity and tonumber(casterStats["elemental_potency"] or casterStats["potency"] or 0)) or 0

    local elemental_resistance = get_element_resistance(stats, element)
    local effective_elemental_resistance = math.max(0, elemental_resistance - elemental_potency)
    if effective_elemental_resistance ~= 0 then
        damage = damage * (1 - effective_elemental_resistance / 100)
    end

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
    if newHp <= 0 and oldHp > 0 then someoneDied = true end

    apply_debuff(casterId, casterType, targetId, stat, power, duration, level)

elseif skillType == "heal" then
    local maxHp = tonumber(stats["hp"] or 100)
    local currentHpShadow = tonumber(stats["current_hp"] or 0)
    if currentHpShadow > 0 then
        local casterEntity = findCasterExplicitCached(casterId, casterType)
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
    result["stamina_delegated_to_php"] = true
    result["stamina_note"] = "handled_by_php"

elseif skillType == "revive" then
    local currentHpShadow = tonumber(stats["current_hp"] or 0)
    if currentHpShadow == 0 then
        local maxHp = tonumber(stats["hp"] or 100)
        local casterEntity = findCasterExplicitCached(casterId, casterType)
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
    return cjson.encode({ error = "Unknown skill/item type: " .. tostring(skillType) })
end

-- Lock handling (maintain original semantics)
if lockTime > 0 then
    local lockKey = "skill_lock:" .. battleId .. ":" .. targetType .. ":" .. targetId
    local currentLock = tonumber(redis.call("GET", lockKey) or 0)
    local now = t_start_secs
    local lockSeconds = math.ceil(lockTime / 1000)
    local newLock = now + lockSeconds
    if newLock > currentLock then
        redis.call("SET", lockKey, newLock)
        redis.call("EXPIRE", lockKey, lockSeconds * 2)
    end
end

-- update entity snapshot for hp/stamina and persist
if stats["current_hp"] ~= nil then entity["stats"]["current_hp"] = stats["current_hp"] end
if stats["current_stamina"] ~= nil then entity["stats"]["current_stamina"] = stats["current_stamina"] end
redis.call("HSET", targetKey, targetId, cjson.encode(entity))

result["target_died"] = someoneDied
result["current_hp"] = math.floor(stats["current_hp"] or 0)
if stats["current_stamina"] ~= nil then entity["stats"]["current_stamina"] = math.floor(stats["current_stamina"]) end

local t2 = redis.call("TIME")
local exec_ms = (t2[1] - t_start[1]) * 1000 + (t2[2] - t_start[2]) / 1000
result["exec_time_ms"] = exec_ms

return cjson.encode(result)
