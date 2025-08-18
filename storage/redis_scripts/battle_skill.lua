-- ARGV[1] = skillType ("physical", "magical", "heal", "buff")
-- ARGV[2] = casterId
-- ARGV[3] = targetId
-- ARGV[4] = skillPower / bonus (opcional, default 0)
-- ARGV[5] = stat (apenas para buff, opcional, default "")
-- ARGV[6] = duration (apenas para buff, opcional, default 0)
-- KEYS[1] = chave Redis do hash (ex: battle:battleId:monsters)

local skillType = ARGV[1] or ""
local casterId = ARGV[2] or ""
local targetId = ARGV[3] or ""
local power = tonumber(ARGV[4]) or 0
local stat = ARGV[5] or ""
local duration = tonumber(ARGV[6]) or 0

local raw = redis.call('HGET', KEYS[1], targetId)
if not raw then
    return cjson.encode({
        error = "Target not found"
    })
end


local entity = cjson.decode(raw)
local stats = entity['stats'] or {} -- NOVO: stats separado

local someoneDied = false
local result = {}

-- Dano físico ou mágico
if skillType == "physical" or skillType == "magical" then
-- NOVO: usa pdefense para físico e mdefense para mágico
local defense = 0
if skillType == "physical" then
    defense = tonumber(stats['pdefense']) or 0
elseif skillType == "magical" then
    defense = tonumber(stats['mdefense']) or 0
end

local currentHp = tonumber(stats['current_hp']) or 0 -- NOVO: usa stats

    local damage = math.max(0, power - defense)
    damage = math.floor(damage)
    local newHp = math.max(0, currentHp - damage)
    newHp = math.floor(newHp)
stats['current_hp'] = newHp -- NOVO: atualiza stats

    result['damage_dealt'] = damage
if newHp <= 0 then
    someoneDied = true
end


-- Cura

elseif skillType == "heal" then
local maxHp = tonumber(stats['hp']) or 100 -- NOVO: usa stats
local currentHp = tonumber(stats['current_hp']) or 0 -- NOVO: usa stats

    local newHp = math.min(maxHp, currentHp + power)
    newHp = math.floor(newHp)
stats['current_hp'] = newHp -- NOVO: atualiza stats

    result['healed_amount'] = math.floor(newHp - currentHp)

-- Buff

elseif skillType == "buff" then
    local buff = {
        caster_id = casterId,
        stat = stat ~= "" and stat or "unknown",
        bonus = math.floor(power),
        duration = math.floor(duration)
    }
redis.call('HSET', KEYS[1] .. ":buffs", casterId, cjson.encode(buff))

    result['buff_applied'] = buff
end

-- Salva o estado atualizado no hash
entity['stats'] = stats -- NOVO: garante que stats atualizados sejam salvos

redis.call('HSET', KEYS[1], targetId, cjson.encode(entity))

-- Resultado final
result['target_died'] = someoneDied
result['current_hp'] = math.floor(stats['current_hp'] or 0) -- NOVO: retorna current_hp de stats


return cjson.encode(result)
