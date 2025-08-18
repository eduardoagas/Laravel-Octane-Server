--[[
Lua script para aplicar skills em batalha:
- Mantém cálculo de dano físico/mágico com multiplicadores weak/medium/strong
- Mantém heal, buff e debuff
- NOVO: agora toda a lógica de cálculo está no Lua
--]]

local skillType = ARGV[1] or ""
local casterId = ARGV[2] or ""
local targetId = ARGV[3] or ""
local power = tonumber(ARGV[4]) or 0
local stat = ARGV[5] or ""
local duration = tonumber(ARGV[6]) or 0
local casterStats = cjson.decode(ARGV[7] or "{}")
local targetStats = cjson.decode(ARGV[8] or "{}")
local level = tonumber(ARGV[9]) or 0

local keys = {KEYS[1], KEYS[2]}

-- Função para buscar target em qualquer hash
local function hget_any(keys, field)
    for i = 1, #keys do
        local raw = redis.call('HGET', keys[i], field)
        if raw then return raw, keys[i] end
    end
    return nil, nil
end

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

-- Função utilitária para ler stats (novos ou antigos nomes)
local function read_stat(tbl, ...)
    for i = 1, select('#', ...) do
        local k = select(i, ...)
        if tbl[k] ~= nil then return tonumber(tbl[k]) end
    end
    return 0
end

-- Calcula defesa baseada no tipo de skill
local function get_defense(stats_table, skillType)
    if skillType == "physical" then return read_stat(stats_table, 'pdefense') end
    return read_stat(stats_table, 'mdefense')
end

-- Multiplicadores de força (NOVO: mantém mecânica antiga do PHP)
local strength_multipliers = {
    weak = 0.9,
    medium = 3.0,
    strong = 5.0
}

-- Determina categoria de força baseada no nível ou power (NOVO)
local function determine_strength(level)
    if level == 1 then return "weak" end       -- ajuste leve, pode personalizar
    if power == 2 then return "medium" end
    if power == 3 then return "strong" end
    return "weak"
end

-- ====================== DANO FÍSICO / MÁGICO ======================
if skillType == "physical" or skillType == "magical" then
    local attack = read_stat(casterStats, skillType == "physical" and "strength" or "intelligence")
    local defense = get_defense(stats, skillType)
    local currentHp = read_stat(stats, 'current_hp')

    -- NOVO: aplica multiplicador de força
    local strength = determine_strength(level)
    local baseDamage = 10 + 1 * (power + attack) -- mesmo cálculo PHP anterior
    local damage = math.max(0, math.floor(baseDamage * (strength_multipliers[strength] or 1)))
    local newHp = math.max(0, currentHp - damage)
    stats['current_hp'] = newHp
    result['damage_dealt'] = damage

    if newHp <= 0 and not statuses['death'] then
        someoneDied = true
        statuses['death'] = { caster_id = casterId, duration = 0, applied_at = redis.call('TIME')[1] }
    elseif newHp > 0 then
        statuses['death'] = nil
    end

-- ====================== HEAL ======================
elseif skillType == "heal" then
    local maxHp = read_stat(stats, 'hp') or 100
    local currentHp = read_stat(stats, 'current_hp')
    local healPower = power + read_stat(casterStats, 'intelligence')
    local newHp = math.min(maxHp, currentHp + healPower)
    stats['current_hp'] = newHp
    result['healed_amount'] = newHp - currentHp
    if newHp > 0 then statuses['death'] = nil end

-- ====================== BUFF ======================
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

-- ====================== DEBUFF ======================
elseif skillType == "debuff" then
    local caster_luk = math.max(1, read_stat(casterStats, 'luck'))
    local target_vit = math.max(1, read_stat(stats, 'vitality', 'vit'))

    -- Determina força do debuff (mesma lógica antiga)
    local debuff_strength = power < 100 and "weak" or power < 300 and "medium" or "strong"
    local base_chances = { weak = 0.10, medium = 0.20, strong = 0.50 }
    local min_chances = { weak = 0.00, medium = 0.01, strong = 0.10 }
    local chance = base_chances[debuff_strength] * (caster_luk / (caster_luk + target_vit))
    chance = math.max(min_chances[debuff_strength], math.min(0.99, chance))

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
        statuses[debuff['stat']] = debuff

        if debuff['stat'] == 'death' and read_stat(stats, 'current_hp') > 0 then
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

-- ====================== SALVA STATS NO REDIS ======================
stats['statuses'] = statuses
entity['stats'] = stats
redis.call('HSET', targetKey, targetId, cjson.encode(entity))

result['target_died'] = someoneDied
result['current_hp'] = read_stat(stats, 'current_hp')

return cjson.encode(result)
