-- KEYS[1] = battle:<id>:characters_data
-- KEYS[2] = battle:<id>:monsters
-- ARGV[1] = skillType ("physical", "magical", "heal", "buff", "debuff", "revive")
-- ARGV[2] = casterId
-- ARGV[3] = targetId
-- ARGV[4] = skillPower / bonus (opcional, default 0)
-- ARGV[5] = stat (apenas para buff/debuff, opcional, default "")
-- ARGV[6] = duration (apenas para buff/debuff, opcional, default 0)
-- ARGV[7] = level (opcional, default 1)
-- ARGV[8] = casterType (character ou monster)  -- NOVO: passado pelo PHP para que possamos salvar junto no Redis
-- ARGV[9] = tickSkillId
local skillType = ARGV[1] or ""
local casterId = ARGV[2] or ""
local targetId = ARGV[3] or ""
local power = tonumber(ARGV[4]) or 0
local stat = ARGV[5] or ""
local duration = tonumber(ARGV[6]) or nil
local level = tonumber(ARGV[7]) or 1
local casterType = ARGV[8] or "" -- NOVO: tipo de quem aplicou a skill (usaremos ao armazenar debuffs/buffs)
local tickSkillId = tonumber(ARGV[9]) or nil

-- Função para buscar target em qualquer hash
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
    return cjson.encode({
        error = "Target not found"
    })
end

local entity = cjson.decode(raw)
local stats = entity['stats'] or {}
stats['statuses'] = stats['statuses'] or {}
local statuses = stats['statuses']

local someoneDied = false
local result = {}

-- contador por alvo para produzir variação entre execuções muito rápidas
local _rand_counter_key = targetKey .. ":rand_counter"

local function nano_random()
    -- pega tempo atual
    local t = redis.call('TIME')
    local secs = tonumber(t[1]) or 0
    local micros = tonumber(t[2]) or 0

    -- incrementa contador e garante expiração curta para não encher o Redis
    local inc = tonumber(redis.call('INCR', _rand_counter_key) or 0)
    if inc == 1 then
        redis.call('EXPIRE', _rand_counter_key, 60) -- expira em 60s
    end

    -- mistura micros + contador para criar seed única
    local seed = secs * 1000000 + ((micros + inc) % 1000000)
    math.randomseed(seed)

    -- aquecimento curto para evitar bias em alguns motores Lua
    math.random();
    math.random()

    -- retorna float em (0,1)
    return math.random()
end

-- Função utilitária para ler stats
local function read_stat(tbl, ...)
    for i = 1, select('#', ...) do
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
        return tonumber(stats_table['pdefense'] or 0)
    else
        return tonumber(stats_table['mdefense'] or 0)
    end
end

-- Função reutilizável para aplicar debuff
-- ALTERAÇÃO: agora recebe casterType para armazenar junto no Redis (caster_type).
local function apply_debuff(casterId, casterType, targetId, targetKey, stat, power, duration, level)
    if stat == nil or stat == "" then
        return
    end

    local function findCaster(cId)
        local raw, _ = hget_any(keys, cId)
        if not raw then
            return nil
        end
        return cjson.decode(raw)
    end

    local casterEntity = findCaster(casterId)
    local casterStats = casterEntity and casterEntity['stats'] or {}
    local caster_luk = math.max(1, tonumber(read_stat(casterStats, 'luck')) or 0)
    local target_vit = math.max(1, tonumber(read_stat(stats, 'vitality', 'vit')) or 0)

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
        -- NOTE: inclui caster_type no objeto salvo (ALTERAÇÃO)
        duration = duration and math.floor(duration) or nil
        local debuff = {
            caster_id = casterId,
            caster_type = casterType, -- NOVO: armazena a origem (character/monster)
            stat = stat,
            power = math.floor(power),
            duration = duration,
            applied_at = redis.call('TIME')[1],
            tick_skill_id = tickSkillId

        }
        local field = tostring(targetId) .. ":" .. debuff['stat'] .. ":" .. tostring(casterId)
        redis.call('HSET', targetKey .. ":debuffs", field, cjson.encode(debuff))
        statuses[debuff['stat']] = {
            caster_id = casterId,
            caster_type = casterType, -- NOVO: também refletido em statuses locais
            power = debuff['power'],
            duration = debuff['duration'],
            applied_at = debuff['applied_at']
        }

        -- Caso especial: debuff de morte instantânea
        if debuff['stat'] == 'death' and (stats['current_hp'] or 0) > 0 then
            stats['current_hp'] = 0
            someoneDied = true
            statuses['death'] = {
                caster_id = casterId,
                caster_type = casterType, -- NOVO: registra tipo do caster que causou a morte
                applied_at = debuff['applied_at']
            }
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

-- DANO FÍSICO / MÁGICO
if skillType == "physical" or skillType == "magical" then
    local defense = get_defense(stats, skillType)
    local currentHp = tonumber(stats['current_hp'] or 0)
    local damage = math.max(0, math.floor(power) - math.floor(defense))
    local newHp = math.max(0, currentHp - damage)
    stats['current_hp'] = math.floor(newHp)
    result['damage_dealt'] = damage
    if newHp <= 0 and not statuses['death'] then
        someoneDied = true
        statuses['death'] = {
            caster_id = casterId,
            duration = 0,
            applied_at = redis.call('TIME')[1]
        }
    elseif newHp > 0 then
        statuses['death'] = nil
    end

    -- Aplica debuff caso skill de dano tenha stat definido
    -- ALTERAÇÃO: passa casterType para que o debuff salvo contenha caster_type
    apply_debuff(casterId, casterType, targetId, targetKey, stat, power, duration, level)

    -- DAMAGE PERCENTUAL
elseif skillType == "percentageDamage" or skillType == "purePercentageDamage" then
    local maxHp = tonumber(stats['hp'] or 100)
    local currentHp = tonumber(stats['current_hp'] or 0)
    local damage = 0

    if skillType == "percentageDamage" then
        damage = math.floor(currentHp * (power / 100))
    else -- purePercentageDamage
        damage = math.floor(maxHp * (power / 100))
    end

    damage = math.max(0, damage)
    local newHp = math.max(0, currentHp - damage)
    stats['current_hp'] = math.floor(newHp)
    result['damage_dealt'] = damage

    if newHp <= 0 and not statuses['death'] then
        someoneDied = true
        statuses['death'] = {
            caster_id = casterId,
            caster_type = casterType,
            applied_at = redis.call('TIME')[1]
        }
    elseif newHp > 0 then
        statuses['death'] = nil
    end

    -- aplica debuff caso skill tenha stat definido (ex: PoisonTick)
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
        statuses['death'] = nil
        stats['current_hp'] = math.floor(newHp)
        result['healed_amount'] = math.floor(power)
        result['revive_applied'] = true
    end

    -- BUFF
elseif skillType == "buff" then
    -- ALTERAÇÃO: inclui caster_type no objeto de buff salvo
    local buff = {
        caster_id = casterId,
        caster_type = casterType, -- NOVO: registra origem do buff
        stat = stat ~= "" and stat or "unknown",
        bonus = math.floor(power),
        duration = math.floor(duration),
        applied_at = redis.call('TIME')[1],
        tick_skill_id = tickSkillId

    }
    -- guardamos pelo campo casterId (mantendo compatibilidade com anterior), mas o JSON agora tem caster_type
    redis.call('HSET', targetKey .. ":buffs", casterId, cjson.encode(buff))
    result['buff_applied'] = buff

    -- DEBUFF PURO
elseif skillType == "debuff" then
    -- ALTERAÇÃO: passa casterType para persistir caster_type no Redis
    apply_debuff(casterId, casterType, targetId, targetKey, stat, power, duration, level)

else
    return cjson.encode({
        error = "Unknown skill type: " .. tostring(skillType)
    })
end

stats['statuses'] = statuses
entity['stats'] = stats
redis.call('HSET', targetKey, targetId, cjson.encode(entity))

result['target_died'] = someoneDied
result['current_hp'] = math.floor(stats['current_hp'] or 0)

return cjson.encode(result)
