-- -*- coding: utf-8 -*-
--
-- Simple JSON encoding/decoding in pure Lua.
-- Minimal implementation for the AutoPoster Lightroom plugin.
--
-- Copyright (C) 2024 AutoPoster Contributors
-- MIT License

local JSON = { _version = "1.0.0" }

--------------------------------------------------------------------------------
-- Encoding
--------------------------------------------------------------------------------

local encode_value -- forward declaration

local function encode_string(val)
    local replacements = {
        ["\\"] = "\\\\",
        ["\""] = "\\\"",
        ["\n"] = "\\n",
        ["\r"] = "\\r",
        ["\t"] = "\\t",
        ["\b"] = "\\b",
        ["\f"] = "\\f",
    }
    val = val:gsub('[\\"\n\r\t\b\f]', replacements)
    -- Escape control characters
    val = val:gsub("[\x00-\x1f]", function(c)
        return string.format("\\u%04x", c:byte())
    end)
    return '"' .. val .. '"'
end

local function encode_table(val, indent, currentIndent)
    local isArray = true
    local maxIndex = 0
    local count = 0
    for k, _ in pairs(val) do
        count = count + 1
        if type(k) ~= "number" or k < 1 or math.floor(k) ~= k then
            isArray = false
            break
        end
        if k > maxIndex then maxIndex = k end
    end
    if isArray and maxIndex ~= count then isArray = false end

    if count == 0 then
        return isArray and "[]" or "{}"
    end

    local parts = {}
    if isArray then
        for i = 1, maxIndex do
            parts[i] = encode_value(val[i], indent, currentIndent)
        end
        return "[" .. table.concat(parts, ",") .. "]"
    else
        for k, v in pairs(val) do
            local keyStr = encode_string(tostring(k))
            local valStr = encode_value(v, indent, currentIndent)
            table.insert(parts, keyStr .. ":" .. valStr)
        end
        return "{" .. table.concat(parts, ",") .. "}"
    end
end

encode_value = function(val, indent, currentIndent)
    local t = type(val)
    if val == nil then
        return "null"
    elseif t == "boolean" then
        return val and "true" or "false"
    elseif t == "number" then
        if val ~= val then return "null" end -- NaN
        if val == math.huge or val == -math.huge then return "null" end
        return tostring(val)
    elseif t == "string" then
        return encode_string(val)
    elseif t == "table" then
        return encode_table(val, indent, currentIndent)
    else
        return "null"
    end
end

function JSON:encode(val)
    return encode_value(val)
end

--------------------------------------------------------------------------------
-- Decoding
--------------------------------------------------------------------------------

local decode_value -- forward declaration

local function skip_whitespace(str, pos)
    while pos <= #str do
        local c = str:byte(pos)
        if c == 32 or c == 9 or c == 10 or c == 13 then
            pos = pos + 1
        else
            break
        end
    end
    return pos
end

local function decode_string(str, pos)
    pos = pos + 1 -- skip opening quote
    local parts = {}
    while pos <= #str do
        local c = str:sub(pos, pos)
        if c == '"' then
            return table.concat(parts), pos + 1
        elseif c == '\\' then
            pos = pos + 1
            local esc = str:sub(pos, pos)
            if esc == '"' or esc == '\\' or esc == '/' then
                table.insert(parts, esc)
            elseif esc == 'n' then table.insert(parts, '\n')
            elseif esc == 'r' then table.insert(parts, '\r')
            elseif esc == 't' then table.insert(parts, '\t')
            elseif esc == 'b' then table.insert(parts, '\b')
            elseif esc == 'f' then table.insert(parts, '\f')
            elseif esc == 'u' then
                local hex = str:sub(pos + 1, pos + 4)
                local code = tonumber(hex, 16)
                if code then
                    if code < 128 then
                        table.insert(parts, string.char(code))
                    else
                        -- Simple UTF-8 encoding
                        if code < 0x800 then
                            table.insert(parts, string.char(
                                0xC0 + math.floor(code / 64),
                                0x80 + (code % 64)
                            ))
                        else
                            table.insert(parts, string.char(
                                0xE0 + math.floor(code / 4096),
                                0x80 + math.floor((code % 4096) / 64),
                                0x80 + (code % 64)
                            ))
                        end
                    end
                end
                pos = pos + 4
            end
            pos = pos + 1
        else
            table.insert(parts, c)
            pos = pos + 1
        end
    end
    error("Unterminated string")
end

local function decode_number(str, pos)
    local startPos = pos
    if str:sub(pos, pos) == '-' then pos = pos + 1 end
    while pos <= #str and str:byte(pos) >= 48 and str:byte(pos) <= 57 do
        pos = pos + 1
    end
    if pos <= #str and str:sub(pos, pos) == '.' then
        pos = pos + 1
        while pos <= #str and str:byte(pos) >= 48 and str:byte(pos) <= 57 do
            pos = pos + 1
        end
    end
    if pos <= #str and (str:sub(pos, pos) == 'e' or str:sub(pos, pos) == 'E') then
        pos = pos + 1
        if pos <= #str and (str:sub(pos, pos) == '+' or str:sub(pos, pos) == '-') then
            pos = pos + 1
        end
        while pos <= #str and str:byte(pos) >= 48 and str:byte(pos) <= 57 do
            pos = pos + 1
        end
    end
    local num = tonumber(str:sub(startPos, pos - 1))
    return num, pos
end

local function decode_object(str, pos)
    local obj = {}
    pos = pos + 1 -- skip {
    pos = skip_whitespace(str, pos)
    if str:sub(pos, pos) == '}' then return obj, pos + 1 end
    while true do
        pos = skip_whitespace(str, pos)
        if str:sub(pos, pos) ~= '"' then error("Expected string key at position " .. pos) end
        local key
        key, pos = decode_string(str, pos)
        pos = skip_whitespace(str, pos)
        if str:sub(pos, pos) ~= ':' then error("Expected colon at position " .. pos) end
        pos = pos + 1
        local val
        val, pos = decode_value(str, pos)
        obj[key] = val
        pos = skip_whitespace(str, pos)
        local c = str:sub(pos, pos)
        if c == '}' then return obj, pos + 1 end
        if c ~= ',' then error("Expected comma or } at position " .. pos) end
        pos = pos + 1
    end
end

local function decode_array(str, pos)
    local arr = {}
    pos = pos + 1 -- skip [
    pos = skip_whitespace(str, pos)
    if str:sub(pos, pos) == ']' then return arr, pos + 1 end
    while true do
        local val
        val, pos = decode_value(str, pos)
        table.insert(arr, val)
        pos = skip_whitespace(str, pos)
        local c = str:sub(pos, pos)
        if c == ']' then return arr, pos + 1 end
        if c ~= ',' then error("Expected comma or ] at position " .. pos) end
        pos = pos + 1
    end
end

decode_value = function(str, pos)
    pos = skip_whitespace(str, pos)
    local c = str:sub(pos, pos)
    if c == '"' then
        return decode_string(str, pos)
    elseif c == '{' then
        return decode_object(str, pos)
    elseif c == '[' then
        return decode_array(str, pos)
    elseif c == 't' then
        if str:sub(pos, pos + 3) == "true" then return true, pos + 4 end
        error("Invalid value at position " .. pos)
    elseif c == 'f' then
        if str:sub(pos, pos + 4) == "false" then return false, pos + 5 end
        error("Invalid value at position " .. pos)
    elseif c == 'n' then
        if str:sub(pos, pos + 3) == "null" then return nil, pos + 4 end
        error("Invalid value at position " .. pos)
    elseif c == '-' or (c >= '0' and c <= '9') then
        return decode_number(str, pos)
    else
        error("Unexpected character '" .. c .. "' at position " .. pos)
    end
end

function JSON:decode(str)
    if type(str) ~= "string" then error("Expected string, got " .. type(str)) end
    local val, pos = decode_value(str, 1)
    return val
end

return JSON
