local lualdap = require "lualdap"

local function required(name)
    local value = os.getenv(name)
    if value == nil or value == "" then
        error(name .. " is not set")
    end
    return value
end

local server = required("PROSODY_LDAP_SERVER")
local group_base = required("PROSODY_LDAP_GROUP_BASE")
local rootdn = required("PROSODY_LDAP_ROOTDN")
local password = required("PROSODY_LDAP_PASSWORD")
local group = required("PROSODY_ADMIN_GROUP")
local domain = required("PROSODY_DOMAIN")
local tls = (os.getenv("PROSODY_LDAP_TLS") or "false") == "true"

local group_dn = "cn=" .. group .. "," .. group_base
local ld = assert(lualdap.open_simple(server, rootdn, password, tls))

local admins = {}
local seen = {}
local found = false

local function add_member(value)
    if type(value) ~= "string" then
        return
    end
    local uid = value:match("^[Uu][Ii][Dd]=([^,]+)")
    if not uid then
        return
    end
    uid = uid:gsub("\\(.)", "%1")
    if not uid:match("^[A-Za-z0-9._-]+$") then
        error("Unsupported LLDAP username in administrator group: " .. uid)
    end
    if seen[uid] then
        return
    end
    seen[uid] = true
    admins[#admins + 1] = uid .. "@" .. domain
end

for _dn, attributes in ld:search{
    base = group_dn,
    scope = "base",
    filter = "(objectClass=*)",
} do
    found = true
    for _, key in ipairs({ "member", "uniqueMember", "uniquemember" }) do
        local values = attributes[key]
        if type(values) == "string" then
            add_member(values)
        elseif type(values) == "table" then
            for _, value in ipairs(values) do
                add_member(value)
            end
        end
    end
end

if not found then
    error("LLDAP administrator group not found: " .. group_dn)
end

table.sort(admins)
print(table.concat(admins, ","))
