-- consume_stamina_optimized.lua
-- Analítico, compatível Redis Lua 5.1, sem goto, top-level pcall seguro

local function safe_encode(tbl)
  local ok, encoded = pcall(cjson.encode, tbl)
  if ok and encoded then return encoded end

  local parts = {"{"}
  local first = true
  for k, v in pairs(tbl) do
    if not first then table.insert(parts, ",") end
    first = false
    local key = tostring(k)
    local val = (type(v) == "number" or type(v) == "boolean") and tostring(v) or '"'..tostring(v):gsub('"','\\"')..'"'
    table.insert(parts, '"'..key..'":'..val)
  end
  table.insert(parts, "}")
  return table.concat(parts)
end

local function main()
  local hkey = KEYS[1]
  local field = ARGV[1]
  local amount = tonumber(ARGV[2]) or 0
  local nowTs = tonumber(ARGV[3]) or os.time()

  local raw = redis.call('HGET', hkey, field)
  if not raw then return safe_encode({ error = "no_data" }) end

  local parsed = cjson.decode(raw) or {}
  local start_time = tonumber(parsed['start_time'] or 0)
  local initial = tonumber(parsed['initial_stamina'] or 0)
  local sMax = tonumber(parsed['max_stamina'] or 0)
  local dex = math.max(1, tonumber(parsed['dexterity'] or 1))
  local used = tonumber(parsed['used_stamina_total'] or 0)

  -- constantes
  local minRate, maxRate, maxDex, alpha = 3.6, 20.0, 300.0, 0.3
  local absBands = {
    {0,50,0.4},{50,150,0.8},{150,350,1.2},{350,700,1.8},{700,1e30,3.0}
  }

  -- cálculo regeneração
  local elapsed = math.max(0, nowTs - start_time)
  local agiFactor = math.pow(math.min(dex / maxDex, 1.0), alpha)
  local baseRegen = minRate + (maxRate - minRate) * agiFactor

  local cur = math.max(0, initial - used)
  local remaining, recovered = elapsed, 0.0

  for i=1,#absBands do
    if remaining <= 0 or cur >= sMax then break end
    local b = absBands[i]
    local bTo, mult = math.min(b[2], sMax), b[3]
    if cur < bTo then
      local rate = baseRegen * mult
      local need = bTo - cur
      local timeToFill = need / rate
      if timeToFill <= remaining then
        cur = cur + need
        recovered = recovered + need
        remaining = remaining - timeToFill
      else
        local gain = rate * remaining
        cur = cur + gain
        recovered = recovered + gain
        remaining = 0
        break
      end
    end
  end

  local current = math.max(0, math.min(sMax, initial + recovered - used))

  -- RECUPERAÇÃO (amount < 0)
  if amount < 0 then
    local recoverAmount = -amount
    local new_current = math.min(sMax, current + recoverAmount)
    parsed['initial_stamina'] = new_current
    parsed['start_time'] = nowTs
    parsed['used_stamina_total'] = 0
    redis.call('HSET', hkey, field, cjson.encode(parsed))
    return safe_encode({ used = used, recovered = recoverAmount, current_after = new_current, note="recovered" })
  end

  -- ZERO: apenas compactar estado
  if amount == 0 then
    parsed['initial_stamina'] = current
    parsed['start_time'] = nowTs
    parsed['used_stamina_total'] = 0
    redis.call('HSET', hkey, field, cjson.encode(parsed))
    return safe_encode({ used = used, current_after = current, note="zero_cost" })
  end

  -- CONSUMO
  if current < amount then
    return safe_encode({ error="insufficient", current=current })
  end

  local new_used = used + amount
  local current_after = math.max(0, current - amount)

  -- compacta estado
  parsed['initial_stamina'] = current_after
  parsed['start_time'] = nowTs
  parsed['used_stamina_total'] = 0
  redis.call('HSET', hkey, field, cjson.encode(parsed))

  return safe_encode({ used = new_used, current_after = current_after })
end

-- TOP-LEVEL PCALL para retorno seguro
local ok, res = pcall(main)
if not ok then return safe_encode({ error="lua_runtime", message=tostring(res) }) end
if res == nil then return safe_encode({ error="lua_no_result" }) end
return res
