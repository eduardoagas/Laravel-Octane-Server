-- KEYS[1] = battle:<id>:characters_data
-- KEYS[2] = battle:<id>:monsters
-- ARGV[1] = skillType ("physical", "magical", "heal", "buff", "debuff")
-- ARGV[2] = casterId
-- ARGV[3] = targetId
-- ARGV[4] = skillPower / bonus (opcional, default 0)
-- ARGV[5] = stat (apenas para buff/debuff, opcional, default "")
-- ARGV[6] = duration (apenas para buff/debuff, opcional, default 0)

local skillType = ARGV[1] or ""
local casterId = ARGV[2] or ""
local targetId = ARGV[3] or ""
local power = tonumber(ARGV[4]) or 0
local stat = ARGV[5] or ""
local duration = tonumber(ARGV[6]) or 0

-- Função para buscar target em qualquer hash
local function hget_any(keys, field)
    for i=1,#keys do
        local raw = redis.call('HGET', keys[i], field)
        if raw then
            return raw, keys[i]
        end
    end
    return nil, nil
end

local keys = {KEYS[1], KEYS[2]}
local raw, targetKey = hget_any(keys, targetId)
if not raw then return cjson.encode({ error = "Target not found" }) end

local entity = cjson.decode(raw)
local stats = entity['stats'] or {}
stats['statuses'] = stats['statuses'] or {}
local statuses = stats['statuses']

local someoneDied = false
local result = {}

-- Função para gerar aleatório baseado em microsegundos
local function nano_random()
    local micros = tonumber(redis.call('TIME')[2] or 0)
    return (micros % 1000000) / 1000000.0
end

-- Função utilitária para ler stats
local function read_stat(tbl, ...)
    for i=1,select('#', ...) do
        local k = select(i, ...)
        if tbl[k] ~= nil then
            return tonumber(tbl[k])
        end
    end
    return nil
end

-- Calcula defesa
local function get_defense(stats_table, skillType)
    if skillType == "physical" then
        return tonumber(stats_table['pdefense'] or stats_table['defense'] or 0)
    else
        return tonumber(stats_table['mdef'] or stats_table['mdefense'] or 0)
    end
end

-- DANO FÍSICO / MÁGICO
if skillType == "physical" or skillType == "magical" then
    local defense = get_defense(stats, skillType)
    local currentHp = tonumber(stats['current_hp'] or stats['hp'] or 0)
    local damage = math.max(0, math.floor(power) - math.floor(defense))
    local newHp = math.max(0, currentHp - damage)
    stats['current_hp'] = math.floor(newHp)
    result['damage_dealt'] = damage

    if newHp <= 0 and not statuses['death'] then
        someoneDied = true
        statuses['death'] = { caster_id = casterId, duration = 0, applied_at = redis.call('TIME')[1] }
    elseif newHp > 0 then
        statuses['death'] = nil
    end

-- HEAL
elseif skillType == "heal" then
    local maxHp = tonumber(stats['hp'] or 100)
    local currentHp = tonumber(stats['current_hp'] or 0)
    local newHp = math.min(maxHp, currentHp + math.floor(power))
    stats['current_hp'] = math.floor(newHp)
    result['healed_amount'] = math.floor(newHp - currentHp)
    if newHp > 0 then statuses['death'] = nil end

-- BUFF
elseif skillType == "buff" then
    local buff = {
        caster_id = casterId,
        stat = stat ~= "" and stat or "unknown",
        bonus = math.floor(power),
        duration = math.floor(duration),
        applied_at = redis.call('TIME')[1]
    }
    redis.call('HSET', targetKey .. ":buffs", casterId, cjson.encode(buff))
    result['buff_applied'] = buff

-- DEBUFF
elseif skillType == "debuff" then
    local function findCaster(cId)
        local raw, _ = hget_any(keys, cId)
        if not raw then return nil end
        return cjson.decode(raw)
    end
    local casterEntity = findCaster(casterId)
    local casterStats = casterEntity and casterEntity['stats'] or {}

    local caster_luck = tonumber(read_stat(casterStats, 'luck')) or 0
    local target_vit = tonumber(read_stat(stats, 'vitality', 'vit')) or 0
    local base_chance = (caster_luck + target_vit) == 0 and 0.5 or caster_luck / (caster_luck + target_vit)
    local chance = math.min(0.99, math.max(0.01, base_chance + (power / 100.0)))

    if nano_random() < chance then
        local debuff = {
            caster_id = casterId,
            stat = stat ~= "" and stat or "unknown",
            power = math.floor(power),
            duration = math.floor(duration),
            applied_at = redis.call('TIME')[1]
        }
        local field = tostring(targetId) .. ":" .. debuff['stat'] .. ":" .. tostring(casterId)
        redis.call('HSET', targetKey .. ":debuffs", field, cjson.encode(debuff))

        statuses[debuff['stat']] = {
            caster_id = casterId,
            power = debuff['power'],
            duration = debuff['duration'],
            applied_at = debuff['applied_at']
        }

        -- efeito instantâneo se for death
        if debuff['stat'] == 'death' and (stats['current_hp'] or 0) > 0 then
            stats['current_hp'] = 0
            someoneDied = true
        end
        result['debuff_applied'] = debuff
    else
        result['debuff_failed'] = true
        result['debuff_chance'] = chance
        result['debuff_roll'] = nano_random()
    end
else
    return cjson.encode({ error = "Unknown skill type: " .. tostring(skillType) })
end

-- Salva stats de volta no entity
stats['statuses'] = statuses
entity['stats'] = stats
redis.call('HSET', targetKey, targetId, cjson.encode(entity))

result['target_died'] = someoneDied
result['current_hp'] = math.floor(stats['current_hp'] or 0)
return cjson.encode(result)
