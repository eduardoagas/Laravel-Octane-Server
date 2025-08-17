-- consume_stamina.lua (analítico, compatível com Redis Lua 5.1)
-- Versão: sem 'goto' (compatível com Lua 5.1), com top-level pcall e retornos seguros.
-- KEYS[1] = redis hash key (battle:<id>:stamina_data)
-- ARGV[1] = field (e.g. "character:123")
-- ARGV[2] = amount (number as string)
-- ARGV[3] = now timestamp (number as string)

local function safe_encode(tbl)
  local ok, encoded = pcall(cjson.encode, tbl)
  if ok and encoded then
    return encoded
  end
  -- fallback manual (garante string JSON)
  local parts = {}
  table.insert(parts, "{")
  local first = true
  for k, v in pairs(tbl) do
    if not first then table.insert(parts, ",") end
    first = false
    local key = tostring(k)
    -- numeric or boolean values are inserted raw, strings quoted
    if type(v) == "number" or type(v) == "boolean" then
      table.insert(parts, '"'..key..'":'..tostring(v))
    else
      -- escape quotes roughly
      local s = tostring(v):gsub('"', '\\"')
      table.insert(parts, '"'..key..'":"'..s..'"')
    end
  end
  table.insert(parts, "}")
  return table.concat(parts)
end

local function main()
  local hkey = KEYS[1]
  local field = ARGV[1]
  local amount = tonumber(ARGV[2] or "0")
  local nowTs = tonumber(ARGV[3] or tostring(os.time()))

  -- leitura do hash
  local raw = redis.call('HGET', hkey, field)
  if not raw then
    return safe_encode({ error = "no_data" })
  end

  local parsed = cjson.decode(raw) or {}

  local start_time = tonumber(parsed['start_time'] or 0)
  local initial = tonumber(parsed['initial_stamina'] or 0)
  local sMax = tonumber(parsed['max_stamina'] or 0)
  local agi = tonumber(parsed['agility'] or 1)
  local used = tonumber(parsed['used_stamina_total'] or 0)

  -- constants (MANTER idênticas ao PHP)
  local minRate = 2.35
  local maxRate = 20.0
  local maxAgi = 300.0
  local alpha = 0.3

  -- ABSOLUTE bands (pontos) - MANTER idêntico ao PHP
  local absBands = {
    {0.0, 50.0, 0.4},
    {50.0, 150.0, 0.8},
    {150.0, 350.0, 1.2},
    {350.0, 700.0, 1.8},
    {700.0, 1e30, 3.0},
  }

  local elapsed = math.max(0, nowTs - start_time)

  -- baseRegen by agi
  local agiFactor = math.pow(math.min(agi / maxAgi, 1.0), alpha)
  local baseRegen = minRate + (maxRate - minRate) * agiFactor -- stamina por segundo

  -- effective current BEFORE recovered
  local cur = initial - used
  if cur < 0 then cur = 0 end

  local remaining = elapsed
  local recovered = 0.0

  -- regen analítico por bandas (sem goto)
  for i = 1, #absBands do
    if remaining <= 0 then break end
    if cur >= sMax then break end

    local band = absBands[i]
    local bTo = band[2]
    if bTo > sMax then bTo = sMax end
    local mult = band[3]

    if cur < bTo then
      local rate = baseRegen * mult
      if rate <= 0 then
        remaining = 0
        break
      end

      local need = bTo - cur
      local timeToFill = need / rate

      if timeToFill <= remaining then
        recovered = recovered + need
        cur = cur + need
        remaining = remaining - timeToFill
      else
        local gain = rate * remaining
        recovered = recovered + gain
        cur = cur + gain
        remaining = 0
        break
      end
    end
    -- continua para próxima banda naturalmente
  end

  -- limitar ao máximo (segurança numérica)
  if (initial + recovered - used) > sMax then
    recovered = recovered - ((initial + recovered - used) - sMax)
  end

  local current = math.max(0, math.min(sMax, initial + recovered - used))

  -- NOVO: custo 0 -> compacta estado e retorna
  if amount <= 0 then
    parsed['initial_stamina'] = current
    parsed['start_time'] = nowTs
    parsed['used_stamina_total'] = 0
    redis.call('HSET', hkey, field, cjson.encode(parsed))

    return safe_encode({
      used = used,
      current_after = current,
      note = "zero_cost"
    })
  end

  -- checagem suficiente (depois da regen)
  if current < amount then
    return safe_encode({ error = "insufficient", current = current })
  end

  -- aplicar consumo
  local new_used = used + amount
  local current_after = math.max(0, current - amount)

  -- compacta estado (reseta used)
  parsed['initial_stamina'] = current_after
  parsed['start_time'] = nowTs
  parsed['used_stamina_total'] = 0
  redis.call('HSET', hkey, field, cjson.encode(parsed))

  return safe_encode({
    used = new_used,
    current_after = current_after
  })
end

-- TOP-LEVEL PCALL: garante retorno string JSON mesmo em runtime error
local ok, res = pcall(main)
if not ok then
  local msg = tostring(res)
  return safe_encode({ error = "lua_runtime", message = msg })
end

if res == nil then
  return safe_encode({ error = "lua_no_result" })
end

return res
