-- KEYS: list of JSON-keys to update (ex: battle:<id>:character:<instId>:consumables, character_session:<charId>:consumables)
-- ARGV[1] = itemId (number or string)
-- ARGV[2] = amount (int)
local itemId = tonumber(ARGV[1]) or tonumber(ARGV[1]) or ARGV[1]
local amount = tonumber(ARGV[2]) or 1
local results = {}

for k=1,#KEYS do
    local key = KEYS[k]
    local raw = redis.call("GET", key)
    if not raw or raw == false then
        results[k] = cjson.encode({found=false, message="key_missing"})
    else
        local arr = cjson.decode(raw)
        if type(arr) ~= "table" then
            results[k] = cjson.encode({found=false, message="not_array"})
        else
            local updated = false
            local newQty = nil
            for i=1,#arr do
                local itm = arr[i]
                local id = itm["id"] or itm.id
                if tostring(id) == tostring(itemId) then
                    local qty = tonumber(itm["quantity"] or itm.quantity or 0) or 0
                    local after = qty - amount
                    if after > 0 then
                        itm["quantity"] = after
                        arr[i] = itm
                        newQty = after
                        updated = true
                    else
                        -- remove entry
                        table.remove(arr, i)
                        newQty = 0
                        updated = true
                    end
                    break
                end
            end
            if updated then
                -- if array empty, set to '[]' (you may prefer DEL)
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

return cjson.encode(results)
