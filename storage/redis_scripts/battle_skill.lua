-- KEYS[1] = battle:<id>:characters_data
-- KEYS[2] = battle:<id>:monsters
-- ARGV[1] = skillType ("physical", "magical", "heal", "buff", "debuff", "revive")
-- ARGV[2] = casterId
-- ARGV[3] = targetId
-- ARGV[4] = skillPower / bonus (opcional, default 0)
-- ARGV[5] = stat (apenas para buff/debuff, opcional, default "")
-- ARGV[6] = duration (apenas para buff/debuff, opcional, default 0)
-- ARGV[7] = level (opcional, default 1)
-- ARGV[8] = casterType (character ou monster)
-- ARGV[9] = tickSkillId
local skillType = ARGV[1] or ""
local casterId = ARGV[2] or ""
local targetId = ARGV[3] or ""
local power = tonumber(ARGV[4]) or 0
local stat = ARGV[5] or ""
local duration = tonumber(ARGV[6]) or nil
local level = tonumber(ARGV[7]) or 1
local casterType = ARGV[8] or ""
local tickSkillId = tonumber(ARGV[9]) or nil

local function hget_any(keys, field)
    for i = 1, #keys do
        local raw = redis.call('HGET', keys[i], field)
        if raw then
            return raw, keys[i]
        end
    end
    return nil, nil
end

local keys = {KEYS[1], KEYS[2]}
local raw, targetKey = hget_any(keys, targetId)
if not raw then
    return cjson.encode({ error = "Target not found" })
end

local entity = cjson.decode(raw)
local stats = entity['stats'] or {}
-- removed: stats['statuses'] handling (we now keep effects in :buffs / :debuffs)

local someoneDied = false
local result = {}

local _rand_counter_key = targetKey .. ":rand_counter"
local function nano_random()
    local t = redis.call('TIME')
    local secs = tonumber(t[1]) or 0
    local micros = tonumber(t[2]) or 0
    local inc = tonumber(redis.call('INCR', _rand_counter_key) or 0)
    if inc == 1 then
        redis.call('EXPIRE', _rand_counter_key, 60)
    end
    local seed = secs * 1000000 + ((micros + inc) % 1000000)
    math.randomseed(seed)
    math.random(); math.random()
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
        return tonumber(stats_table['pdefense'] or 0)
    else
        return tonumber(stats_table['mdefense'] or 0)
    end
end

-- apply_debuff agora salva em targetKey .. ":debuffs"
local function apply_debuff(casterId, casterType, targetId, targetKey, stat, power, duration, level)
    if stat == nil or stat == "" then return end

    local function findCaster(cId)
        local raw, _ = hget_any(keys, cId)
        if not raw then return nil end
        return cjson.decode(raw)
    end

    local casterEntity = findCaster(casterId)
    local casterStats = casterEntity and casterEntity['stats'] or {}
    local caster_luk = math.max(1, tonumber(read_stat(casterStats, 'luck')) or 0)
    local target_vit = math.max(1, tonumber(read_stat(stats, 'vitality', 'vit')) or 0)

    local debuff_strength
    if level == 2 then debuff_strength = "medium"
    elseif level == 3 then debuff_strength = "strong"
    else debuff_strength = "weak" end

    local stat_chance = caster_luk / (caster_luk + target_vit)
    local base_chances = { weak = 0.10, medium = 0.20, strong = 0.50 }
    local min_chances = { weak = 0.00, medium = 0.01, strong = 0.10 }
    local chance = base_chances[debuff_strength] * stat_chance
    chance = math.max(min_chances[debuff_strength], math.min(0.99, chance))

    local roll = nano_random()
    if roll < chance then
        duration = duration and math.floor(duration) or nil
        local debuff = {
            caster_id = casterId,
            caster_type = casterType, -- store origin
            stat = stat,
            power = math.floor(power),
            duration = duration,
            applied_at = redis.call('TIME')[1],
            tick_skill_id = tickSkillId
        }
        local field = tostring(targetId) .. ":" .. debuff['stat'] .. ":" .. tostring(casterId)
        redis.call('HSET', targetKey .. ":debuffs", field, cjson.encode(debuff))

        -- special: instant death debuff
        if debuff['stat'] == 'death' and tonumber(stats['current_hp'] or 0) > 0 then
            stats['current_hp'] = 0
            someoneDied = true
        end

        result['debuff_applied'] = debuff
        result['debuff_chance'] = chance
        result['debuff_roll'] = roll
    else
        result['debuff_applied'] = nil
        result['debuff_failed'] = true
        result['debuff_chance'] = chance
        result['debuff_roll'] = roll
    end
end

-- DAMAGE physical / magical
if skillType == "physical" or skillType == "magical" then
    local defense = get_defense(stats, skillType)
    local currentHp = tonumber(stats['current_hp'] or 0)         -- PREVIOUS HP
    local damage = math.max(0, math.floor(power) - math.floor(defense))
    local newHp = math.max(0, currentHp - damage)
    stats['current_hp'] = math.floor(newHp)
    result['damage_dealt'] = damage

    -- DETECT NEW DEATH: if we went from >0 to <=0 it's a new death
    if newHp <= 0 and currentHp > 0 then
        someoneDied = true
        -- we do not write statuses object; persistence of death-related effects lives in :debuffs
    end

    -- Aplica debuff (persistido em :debuffs)
    apply_debuff(casterId, casterType, targetId, targetKey, stat, power, duration, level)

-- PERCENT / PUREPERCENT DAMAGE
elseif skillType == "percentageDamage" or skillType == "purePercentageDamage" then
    local maxHp = tonumber(stats['hp'] or 100)
    local currentHp = tonumber(stats['current_hp'] or 0)
    local damage = 0
    if skillType == "percentageDamage" then
        damage = math.floor(currentHp * (power / 100))
    else
        damage = math.floor(maxHp * (power / 100))
    end
    damage = math.max(0, damage)
    local newHp = math.max(0, currentHp - damage)
    stats['current_hp'] = math.floor(newHp)
    result['damage_dealt'] = damage

    if newHp <= 0 and currentHp > 0 then
        someoneDied = true
    end

    apply_debuff(casterId, casterType, targetId, targetKey, stat, power, duration, level)

-- HEAL
elseif skillType == "heal" then
    local maxHp = tonumber(stats['hp'] or 100)
    local currentHp = tonumber(stats['current_hp'] or 0)
    if currentHp > 0 then
        local newHp = math.min(maxHp, currentHp + math.floor(power))
        stats['current_hp'] = math.floor(newHp)
        result['healed_amount'] = math.floor(power)
    end

-- REVIVE
elseif skillType == "revive" then
    local currentHp = tonumber(stats['current_hp'] or 0)
    if currentHp == 0 then
        local maxHp = tonumber(stats['hp'] or 100)
        local newHp = math.min(maxHp, currentHp + math.floor(power))
        stats['current_hp'] = math.floor(newHp)
        result['healed_amount'] = math.floor(power)
        result['revive_applied'] = true
    end

-- BUFF
elseif skillType == "buff" then
    local buff = {
        caster_id = casterId,
        caster_type = casterType,
        stat = stat ~= "" and stat or "unknown",
        bonus = math.floor(power),
        duration = math.floor(duration),
        applied_at = redis.call('TIME')[1],
        tick_skill_id = tickSkillId
    }
    redis.call('HSET', targetKey .. ":buffs", casterId, cjson.encode(buff))
    result['buff_applied'] = buff

-- DEBUFF
elseif skillType == "debuff" then
    apply_debuff(casterId, casterType, targetId, targetKey, stat, power, duration, level)

else
    return cjson.encode({ error = "Unknown skill type: " .. tostring(skillType) })
end

-- persist entity (note: we no longer write stats['statuses'])
entity['stats'] = stats
redis.call('HSET', targetKey, targetId, cjson.encode(entity))

result['target_died'] = someoneDied
result['current_hp'] = math.floor(stats['current_hp'] or 0)

return cjson.encode(result)
