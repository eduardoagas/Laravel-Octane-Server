-- storage/redis_scripts/battle_skill_indexed.lua
-- Versão index-based com suporte a stackable buffs/debuffs
-- PER-INSTANCE: escreve/ler buffs/debuffs em <targetKey>:<instanceId>:buffs|debuffs
-- KEYS[1] = target hash (ex: battle:<id>:characters_data OR battle:<id>:monsters)
-- ARGV:
--  1 = skillType
--  2 = casterId
--  3 = targetId (instanceId)
--  4 = power
--  5 = stat
--  6 = duration
--  7 = level
--  8 = casterType ("character"|"monster")
--  9 = tickSkillId
-- 10 = tickInterval
-- 11 = battleId
-- 12 = stackable ("1" ou "0")
-- 13 = max_stacks
-- 14 = stack_behavior ("add"|"refresh"|"replace")

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

-- read target entity directly from provided targetKey (NO FALLBACK)
local raw = redis.call("HGET", targetKey, targetId)
if not raw then
  return cjson.encode({ error = "Target not found in key " .. tostring(targetKey) })
end

local entity = cjson.decode(raw)
local stats = entity["stats"] or {}

local someoneDied = false
local result = {}


-- PER-INSTANCE keys
local debuffsHashKey = targetKey .. ":" .. tostring(targetId) .. ":debuffs"
local buffsHashKey   = targetKey .. ":" .. tostring(targetId) .. ":buffs"
local debuffIndexInstance = targetKey .. ":" .. tostring(targetId) .. ":debuff_index"
local buffIndexInstance   = targetKey .. ":" .. tostring(targetId) .. ":buff_index"

-- função auxiliar para aplicar deltas
local function apply_effects(hashKey, statsTable)
  local entries = redis.call("HGETALL", hashKey)
  for i=1,#entries,2 do
    local buffId = entries[i]
    local buffData = cjson.decode(entries[i+1])

    -- pega qual stat deve ser modificado
    local statName = buffData["stat"]
    local bonus    = tonumber(buffData["bonus"] or 0)

    if statName and bonus ~= 0 then
      -- inicializa se não existir
      local current = tonumber(statsTable[statName] or 0)
      statsTable[statName] = current + bonus
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
    return tonumber(stats_table["physical_defense"] or 0)
  else
    return tonumber(stats_table["magical_defense"] or 0)
  end
end

-- resolve caster explicitly (no scanning)
local function findCasterExplicit(cId, cType)
  if cId == nil or cId == "" then return nil end
  if not battleId or battleId == "" then return nil end

  local casterKey
  if cType == "character" or cType == "player" then
    casterKey = "battle:" .. battleId .. ":characters_data"
  else
    casterKey = "battle:" .. battleId .. ":monsters"
  end

  local rawCaster = redis.call("HGET", casterKey, tostring(cId))
  if not rawCaster then return nil end
  return cjson.decode(rawCaster)
end

-- apply_debuff (per-instance)
local function apply_debuff(casterId, casterType, targetId, stat, power, duration, level)
  if stat == nil or stat == "" then return end

  local casterEntity = findCasterExplicit(casterId, casterType)
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
      redis.call("SADD", buffIndexInstance, field)
      result["buff_applied"] = old
    else
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

-- === Skill handling ===
if skillType == "physical" or skillType == "magical" then
  local defense = get_defense(stats, skillType)
  local currentHp = tonumber(stats["current_hp"] or 0)
  local damage = math.max(0, math.floor(power) - math.floor(defense))
  local newHp = math.max(0, currentHp - damage)
  stats["current_hp"] = math.floor(newHp)
  result["damage_dealt"] = damage
  if newHp <= 0 and currentHp > 0 then someoneDied = true end

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

-- persist updated entity back to the provided targetKey (no fallback)
entity["stats"] = stats
redis.call("HSET", targetKey, targetId, cjson.encode(entity))

result["target_died"] = someoneDied
result["current_hp"] = math.floor(stats["current_hp"] or 0)
return cjson.encode(result)
