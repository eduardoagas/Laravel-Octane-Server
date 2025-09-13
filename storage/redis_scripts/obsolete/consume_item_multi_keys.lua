-- KEYS: list of JSON-keys to update (ex: battle:<id>:character:<instId>:consumables, character_session:<charId>:consumables)
-- ARGV[1] = itemId (number or string)
-- ARGV[2] = amount (int)

local itemIdStr = tostring(ARGV[1] or "")
local amount = tonumber(ARGV[2]) or 1
local results = {}

for k=1,#KEYS do
    local key = KEYS[k]

    -- Prefer hash-based storage (HGETALL). Se for hash, processa como hash.
    local hlen = tonumber(redis.call("HLEN", key) or 0)
    if hlen and hlen > 0 then
        local hash = redis.call("HGETALL", key)
        local updated = false
        local newQty = nil

        -- hash retornado é {field1, val1, field2, val2, ...}
        for i = 1, #hash, 2 do
            local field = tostring(hash[i])
            local jsonVal = hash[i + 1]

            -- otimização: se o field já for o itemId, só decodifica esse json
            if field == itemIdStr then
                local ok, itm = pcall(cjson.decode, jsonVal)
                if ok and type(itm) == "table" then
                    local qty = tonumber(itm["quantity"] or itm.quantity or 0) or 0
                    local after = qty - amount
                    if after > 0 then
                        itm["quantity"] = after
                        redis.call("HSET", key, field, cjson.encode(itm))
                        newQty = after
                    else
                        redis.call("HDEL", key, field)
                        newQty = 0
                    end
                    updated = true
                else
                    -- JSON inválido no field; reporta erro específico
                    results[k] = cjson.encode({found=false, message="invalid_json_in_field", field=field})
                    updated = false
                end
                break
            else
                -- caso field não seja igual ao itemId, decodifica e checa id interno (precaução)
                local ok, itm = pcall(cjson.decode, jsonVal)
                if ok and type(itm) == "table" then
                    local id = itm["id"] or itm.id
                    if tostring(id) == itemIdStr then
                        local qty = tonumber(itm["quantity"] or itm.quantity or 0) or 0
                        local after = qty - amount
                        if after > 0 then
                            itm["quantity"] = after
                            redis.call("HSET", key, field, cjson.encode(itm))
                            newQty = after
                        else
                            redis.call("HDEL", key, field)
                            newQty = 0
                        end
                        updated = true
                        break
                    end
                end
            end
        end

        if updated then
            -- se o hash ficou vazio, remove a key
            if tonumber(redis.call("HLEN", key) or 0) == 0 then
                redis.call("DEL", key)
            end
            results[k] = cjson.encode({found=true, updated=true, new_quantity=newQty})
        else
            -- se results[k] já foi setado (ex: invalid_json_in_field), não sobrescrever
            if not results[k] then
                results[k] = cjson.encode({found=false, message="item_not_found"})
            end
        end

    else
        -- Fallback para formato antigo (GET -> JSON array)
        local raw = redis.call("GET", key)
        if not raw or raw == false then
            results[k] = cjson.encode({found=false, message="key_missing"})
        else
            local ok, arr = pcall(cjson.decode, raw)
            if not ok or type(arr) ~= "table" then
                results[k] = cjson.encode({found=false, message="not_array_or_invalid_json"})
            else
                local updated = false
                local newQty = nil
                for i = 1, #arr do
                    local itm = arr[i]
                    local id = itm["id"] or itm.id
                    if tostring(id) == itemIdStr then
                        local qty = tonumber(itm["quantity"] or itm.quantity or 0) or 0
                        local after = qty - amount
                        if after > 0 then
                            itm["quantity"] = after
                            arr[i] = itm
                            newQty = after
                        else
                            table.remove(arr, i)
                            newQty = 0
                        end
                        updated = true
                        break
                    end
                end

                if updated then
                    if #arr == 0 then
                        redis.call("DEL", key)
                    else
                        redis.call("SET", key, cjson.encode(arr))
                    end
                    results[k] = cjson.encode({found=true, updated=true, new_quantity=newQty})
                else
                    results[k] = cjson.encode({found=false, message="item_not_found"})
                end
            end
        end
    end
end

return cjson.encode(results)
