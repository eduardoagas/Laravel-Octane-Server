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
if not raw then return cjson.encode({error="Target not found"}) end

local entity = cjson.decode(raw)
local someoneDied = false
local result = {}

-- Dano físico ou mágico
if skillType == "physical" or skillType == "magical" then
    local defense = tonumber(entity['defense']) or 0
    local currentHp = tonumber(entity['hp']) or 0
    local damage = math.max(0, power - defense)
    damage = math.floor(damage)
    local newHp = math.max(0, currentHp - damage)
    newHp = math.floor(newHp)
    entity['hp'] = newHp
    result['damage_dealt'] = damage
    if newHp <= 0 then someoneDied = true end

-- Cura
elseif skillType == "heal" then
    local maxHp = tonumber(entity['max_hp']) or 100
    local currentHp = tonumber(entity['hp']) or 0
    local newHp = math.min(maxHp, currentHp + power)
    newHp = math.floor(newHp)
    entity['hp'] = newHp
    result['healed_amount'] = math.floor(newHp - currentHp)

-- Buff
elseif skillType == "buff" then
    local buff = {
        caster_id = casterId,
        stat = stat ~= "" and stat or "unknown",
        bonus = math.floor(power),
        duration = math.floor(duration)
    }
    redis.call('HSET', KEYS[1]..":buffs", casterId, cjson.encode(buff))
    result['buff_applied'] = buff
end

-- Salva o estado atualizado
redis.call('HSET', KEYS[1], targetId, cjson.encode(entity))

-- Resultado final
result['target_died'] = someoneDied
result['current_hp'] = math.floor(entity['hp'])

return cjson.encode(result)
