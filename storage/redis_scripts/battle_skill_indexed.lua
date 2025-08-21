-- storage/redis_scripts/battle_skill_indexed.lua
-- Versão index-based com suporte a stackable buffs/debuffs
-- KEYS[1] = target hash (ex: battle:<id>:characters_data OR battle:<id>:monsters)
-- ARGV:
--  1 = skillType ("physical","magical","heal","buff","debuff","revive","percentageDamage","purePercentageDamage")
--  2 = casterId
--  3 = targetId
--  4 = power
--  5 = stat
--  6 = duration
--  7 = level
--  8 = casterType ("character"|"monster")
--  9 = tickSkillId
-- 10 = tickInterval
-- 11 = battleId (NOVO — necessário para chaves centrais)
-- 12 = stackable ( "1" ou "0" )  -- NOVO
-- 13 = max_stacks (integer, default 1) -- NOVO
-- 14 = stack_behavior (string: "add"|"refresh"|"replace") -- NOVO

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
local stackBehavior = ARGV[14] or "add"

-- helper to look up target across provided key (compat)
local function hget_any(keys, field)
  for i = 1, #keys do
    local raw = redis.call("HGET", keys[i], field)
    if raw then return raw, keys[i] end
  end
  return nil, nil
end

local keys = { targetKey }
local raw, foundKey = hget_any(keys, targetId)
if not raw then
  return cjson.encode({ error = "Target not found" })
end

local entity = cjson.decode(raw)
local stats = entity["stats"] or {}

local someoneDied = false
local result = {}

local _rand_counter_key = foundKey .. ":rand_counter"
local function nano_random()
  local t = redis.call("TIME")
  local secs = tonumber(t[1]) or 0
  local micros = tonumber(t[2]) or 0
  local inc = tonumber(redis.call("INCR", _rand_counter_key) or 0)
  if inc == 1 then redis.call("EXPIRE", _rand_counter_key, 60) end
  local seed = secs * 1000000 + ((micros + inc) % 1000000)
  math.randomseed(seed)
  math.random(); math.random()
  return math.random()
end

local function read_stat(tbl, ...)
  for i = 1, select('#', ...) do
    local k = select(i, ...)
    if tbl[k] ~= nil then return tonumber(tbl[k]) end
  end
  return nil
end

local function get_defense(stats_table, skillType)
  if skillType == "physical" then
    return tonumber(stats_table["pdefense"] or 0)
  else
    return tonumber(stats_table["mdefense"] or 0)
  end
end

-- NOVO: keys centrais + índices
local debuffsHashKey = "battle:" .. targetKey .. ":debuffs"
local buffsHashKey   = "battle:" .. targetKey .. ":buffs"
local debuffIndexGlobal = "battle:" ..  targetKey .. ":debuff_index"
local buffIndexGlobal   = "battle:" ..  targetKey .. ":buff_index"
local function debuffInstanceIndex(inst) return "battle:" .. targetKey .. ":debuff_index:instance:" .. inst end
local function buffInstanceIndex(inst) return "battle:" .. targetKey .. ":buff_index:instance:" .. inst end

-- Internal: apply debuff (NOVO: writes to central hash + indices, supports stacking)
local function apply_debuff(casterId, casterType, targetId, stat, power, duration, level)
  if stat == nil or stat == "" then return end

  -- simple chance calculation as before
  local function findCaster(cId)
    local raw, _ = hget_any(keys, cId)
    if not raw then return nil end
    return cjson.decode(raw)
  end
  local casterEntity = findCaster(casterId)
  local casterStats = casterEntity and casterEntity["stats"] or {}
  local caster_luk = math.max(1, tonumber(read_stat(casterStats, "luck")) or 0)
  local target_vit = math.max(1, tonumber(read_stat(stats, "vitality", "vit")) or 0)

  local debuff_strength
  if level == 2 then debuff_strength = "medium"
  elseif level == 3 then debuff_strength = "strong"
  else debuff_strength = "weak" end

  local stat_chance = caster_luk / (caster_luk + target_vit)
  local base_chances = { weak = 0.10, medium = 0.20, strong = 0.50 }
  local min_chances  = { weak = 0.00, medium = 0.01, strong = 0.10 }
  local chance = base_chances[debuff_strength] * stat_chance
  chance = math.max(min_chances[debuff_strength], math.min(0.99, chance))

  local roll = nano_random()
  if roll < chance then
    duration = duration and math.floor(duration) or nil
    local field = tostring(targetId) .. ":" .. stat .. ":" .. tostring(casterId)
    local exists = redis.call("HGET", debuffsHashKey, field)
    if exists then
      -- Existing effect for same caster + stat -> apply stack policy
      local old = cjson.decode(exists)
      if stackableFlag then
        -- STACKABLE: behavior depends on stackBehavior
        local oldStacks = tonumber(old["stacks"] or 1)
        local newStacks = oldStacks
        if stackBehavior == "add" then
          newStacks = math.min(maxStacks, oldStacks + 1)
          -- sum power additive (power is per application)
          local newPower = (old["power"] or 0) + math.floor(power)
          old["power"] = newPower
          old["stacks"] = newStacks
          old["duration"] = duration -- refresh duration to new duration (policy)
          old["applied_at"] = redis.call("TIME")[1]
        elseif stackBehavior == "refresh" then
          -- don't increase stacks, refresh duration and update power if incoming is larger
          old["duration"] = duration
          old["applied_at"] = redis.call("TIME")[1]
          if math.floor(power) > (old["power"] or 0) then
            old["power"] = math.floor(power)
          end
        elseif stackBehavior == "replace" then
          -- replace old effect entirely
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
          -- fallback to add
          local newPower = (old["power"] or 0) + math.floor(power)
          old["power"] = newPower
          old["stacks"] = math.min(maxStacks, oldStacks + 1)
          old["duration"] = duration
          old["applied_at"] = redis.call("TIME")[1]
        end
        redis.call("HSET", debuffsHashKey, field, cjson.encode(old))
        -- ensure indexes exist
        redis.call("SADD", debuffIndexGlobal, field)
        redis.call("SADD", debuffInstanceIndex(tostring(targetId)), field)
        result["debuff_applied"] = old
      else
        -- NOT stackable: use refresh/replace semantics (we choose refresh if new incoming duration/power better)
        -- default: refresh duration and take max power
        old["duration"] = duration
        old["applied_at"] = redis.call("TIME")[1]
        if math.floor(power) > (old["power"] or 0) then
          old["power"] = math.floor(power)
        end
        redis.call("HSET", debuffsHashKey, field, cjson.encode(old))
        -- indexes already present
        result["debuff_applied"] = old
      end
    else
      -- No existing entry: create new
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
      redis.call("SADD", debuffIndexGlobal, field)
      redis.call("SADD", debuffInstanceIndex(tostring(targetId)), field)
      -- instant death special-case
      if debuff["stat"] == "death" and tonumber(stats["current_hp"] or 0) > 0 then
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

-- Handle buff similarly (NOVO: supports stacking)
local function apply_buff(casterId, casterType, targetId, stat, power, duration)
  if stat == nil or stat == "" then return end
  local field = tostring(targetId) .. ":" .. stat .. ":" .. tostring(casterId)
  local exists = redis.call("HGET", buffsHashKey, field)
  if exists then
    local old = cjson.decode(exists)
    if stackableFlag then
      local oldStacks = tonumber(old["stacks"] or 1)
      if stackBehavior == "add" then
        old["stacks"] = math.min(maxStacks, oldStacks + 1)
        old["bonus"] = (old["bonus"] or 0) + math.floor(power)
        old["duration"] = duration
        old["applied_at"] = redis.call("TIME")[1]
      elseif stackBehavior == "refresh" then
        old["duration"] = duration
        old["applied_at"] = redis.call("TIME")[1]
        if math.floor(power) > (old["bonus"] or 0) then
          old["bonus"] = math.floor(power)
        end
      elseif stackBehavior == "replace" then
        old = {
          caster_id = casterId,
          caster_type = casterType,
          stat = stat,
          bonus = math.floor(power),
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
        old["bonus"] = (old["bonus"] or 0) + math.floor(power)
        old["duration"] = duration
        old["applied_at"] = redis.call("TIME")[1]
      end
      redis.call("HSET", buffsHashKey, field, cjson.encode(old))
      redis.call("SADD", buffIndexGlobal, field)
      redis.call("SADD", buffInstanceIndex(tostring(targetId)), field)
      result["buff_applied"] = old
    else
      -- not stackable -> refresh / take max bonus
      old["duration"] = duration
      old["applied_at"] = redis.call("TIME")[1]
      if math.floor(power) > (old["bonus"] or 0) then
        old["bonus"] = math.floor(power)
      end
      redis.call("HSET", buffsHashKey, field, cjson.encode(old))
      result["buff_applied"] = old
    end
  else
    local buff = {
      caster_id = casterId,
      caster_type = casterType,
      stat = stat ~= "" and stat or "unknown",
      bonus = math.floor(power),
      duration = math.floor(duration),
      applied_at = redis.call("TIME")[1],
      tick_skill_id = tickSkillId,
      tick_interval = tickInterval,
      stacks = 1,
      max_stacks = maxStacks,
      stack_behavior = stackBehavior
    }
    redis.call("HSET", buffsHashKey, field, cjson.encode(buff))
    redis.call("SADD", buffIndexGlobal, field)
    redis.call("SADD", buffInstanceIndex(tostring(targetId)), field)
    result["buff_applied"] = buff
  end
end

-- === Skill handling (damage/heal/etc) - same logic as before (simplified here) ===
if skillType == "physical" or skillType == "magical" then
  local defense = get_defense(stats, skillType)
  local currentHp = tonumber(stats["current_hp"] or 0)
  local damage = math.max(0, math.floor(power) - math.floor(defense))
  local newHp = math.max(0, currentHp - damage)
  stats["current_hp"] = math.floor(newHp)
  result["damage_dealt"] = damage
  if newHp <= 0 and currentHp > 0 then someoneDied = true end

  -- apply potential debuff if stat present
  apply_debuff(casterId, casterType, targetId, stat, power, duration, level)

elseif skillType == "percentageDamage" or skillType == "purePercentageDamage" then
  local maxHp = tonumber(stats["hp"] or 100)
  local currentHp = tonumber(stats["current_hp"] or 0)
  local damage = 0
  if skillType == "percentageDamage" then
    damage = math.floor(currentHp * (power / 100))
  else
    damage = math.floor(maxHp * (power / 100))
  end
  damage = math.max(0, damage)
  local newHp = math.max(0, currentHp - damage)
  stats["current_hp"] = math.floor(newHp)
  result["damage_dealt"] = damage
  if newHp <= 0 and currentHp > 0 then someoneDied = true end

  apply_debuff(casterId, casterType, targetId, stat, power, duration, level)

elseif skillType == "heal" then
  local maxHp = tonumber(stats["hp"] or 100)
  local currentHp = tonumber(stats["current_hp"] or 0)
  if currentHp > 0 then
    local newHp = math.min(maxHp, currentHp + math.floor(power))
    stats["current_hp"] = math.floor(newHp)
    result["healed_amount"] = math.floor(power)
  end

elseif skillType == "revive" then
  local currentHp = tonumber(stats["current_hp"] or 0)
  if currentHp == 0 then
    local maxHp = tonumber(stats["hp"] or 100)
    local newHp = math.min(maxHp, currentHp + math.floor(power))
    stats["current_hp"] = math.floor(newHp)
    result["healed_amount"] = math.floor(power)
    result["revive_applied"] = true
  end

elseif skillType == "buff" then
  apply_buff(casterId, casterType, targetId, stat, power, duration)

elseif skillType == "debuff" then
  apply_debuff(casterId, casterType, targetId, stat, power, duration, level)

else
  return cjson.encode({ error = "Unknown skill type: " .. tostring(skillType) })
end

-- persist updated entity
entity["stats"] = stats
redis.call("HSET", foundKey, targetId, cjson.encode(entity))

result["target_died"] = someoneDied
result["current_hp"] = math.floor(stats["current_hp"] or 0)
return cjson.encode(result)
