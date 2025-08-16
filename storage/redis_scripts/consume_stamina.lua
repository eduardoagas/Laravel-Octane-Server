-- consume_stamina.lua
-- KEYS[1] = redis hash key (battle:<id>:stamina_data)
-- ARGV[1] = field (e.g. "character:123")
-- ARGV[2] = amount (number as string)
-- ARGV[3] = now timestamp (number as string)

local hkey = KEYS[1]
local field = ARGV[1]
local amount = tonumber(ARGV[2] or "0")
local nowTs = tonumber(ARGV[3] or tostring(os.time()))

local raw = redis.call('HGET', hkey, field)
if not raw then
  return cjson.encode({ error = "no_data" })
end

local parsed = cjson.decode(raw)

local start_time = tonumber(parsed['start_time'] or 0)
local initial = tonumber(parsed['initial_stamina'] or 0)
local sMax = tonumber(parsed['max_stamina'] or 0)
local agi = tonumber(parsed['agility'] or 1)
local used = tonumber(parsed['used_stamina_total'] or 0)

-- constants (must match PHP)
local minRate = 1
local maxRate = 14
local maxAgi = 300
local alpha = 0.6
local beta = 0.4
local B = 1.7

local elapsed = math.max(0, nowTs - start_time)

local agiFactor = math.pow(math.min(agi / maxAgi, 1.0), alpha)
local baseRegen = minRate + (maxRate - minRate) * agiFactor

local maxInitial = 100
local clampedInitial = math.min(initial, maxInitial)
local betaFactor = 1 + (B - 1) * math.pow(clampedInitial / maxInitial, beta)

local recovered = baseRegen * betaFactor * elapsed
local current = math.min(initial + recovered - used, sMax)
current = math.max(0, current)

if current < amount then
  return cjson.encode({ error = "insufficient", current = current })
end

-- increment used_stamina_total atomically
local new_used = used + amount
parsed['used_stamina_total'] = new_used

-- persist back
redis.call('HSET', hkey, field, cjson.encode(parsed))

local current_after = math.max(0, current - amount)

-- return structured JSON
return cjson.encode({
  used = new_used,
  current_after = current_after
})
