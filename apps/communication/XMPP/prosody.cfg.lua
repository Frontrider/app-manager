-- Prosody configuration managed by the app catalog.

-- Prosody 13 provides a sandboxed Lua table for configuration. Fall back to
-- the classic globals if an older configuration environment is used.
local Lua = Lua or { os = os, assert = assert }

local function env(name, default)
    local value = Lua.os.getenv(name)
    if value == nil or value == "" then
        return default
    end
    return value
end

local function split(value)
    local result = {}
    if value == nil or value == "" then
        return result
    end
    for part in value:gmatch("[^,]+") do
        result[#result + 1] = part
    end
    return result
end

local domain = Lua.assert(Lua.os.getenv("PROSODY_DOMAIN"), "PROSODY_DOMAIN is not set")
local public_domain = env("PROSODY_PUBLIC_DOMAIN", domain)
local scheme = env("PROSODY_SCHEME", "https")

-- Community modules are installed here by the image. The stock configuration
-- is replaced, so the path must be declared explicitly.
plugin_paths = { "/etc/prosody/modules" }

admins = split(Lua.os.getenv("PROSODY_ADMINS"))

authentication = "ldap"
ldap_base = Lua.assert(Lua.os.getenv("PROSODY_LDAP_BASE"), "PROSODY_LDAP_BASE is not set")
ldap_server = Lua.assert(Lua.os.getenv("PROSODY_LDAP_SERVER"), "PROSODY_LDAP_SERVER is not set")
ldap_rootdn = Lua.assert(Lua.os.getenv("PROSODY_LDAP_ROOTDN"), "PROSODY_LDAP_ROOTDN is not set")
ldap_password = Lua.assert(Lua.os.getenv("PROSODY_LDAP_PASSWORD"), "PROSODY_LDAP_PASSWORD is not set")
ldap_filter = "(&(uid=$user)(objectClass=person))"
ldap_scope = "subtree"
ldap_mode = "bind"
ldap_tls = (env("PROSODY_LDAP_TLS", "false") == "true")

allow_registration = false
c2s_require_encryption = false
allow_unencrypted_plain_auth = true
-- Federation is intentionally open to any peer reachable through the private
-- overlay. Membership is enforced by the overlay ACL, not by an s2s allowlist.
-- The overlay provides transport encryption, so Prosody itself does not need
-- certificates or certificate validation between nodes. Dialback authenticates
-- the remote domain.
s2s_require_encryption = false
s2s_secure_auth = false
-- Caddy terminates client TLS for BOSH/WebSocket and for direct TLS on 5223.
-- Prosody itself has no certificates; federation uses plaintext s2s with
-- dialback inside the private overlay.
consider_bosh_secure = true
consider_websocket_secure = true
local private_networks = {
    "127.0.0.1",
    "::1",
    "10.0.0.0/8",
    "172.16.0.0/12",
    "192.168.0.0/16",
}
trusted_proxies = private_networks

-- Caddy terminates TLS on 5223 and forwards plaintext XMPP here with a PROXY
-- header. Keep this port internal: any peer that can reach it can spoof the
-- original client address.
proxy_port_mappings = {
    [15222] = "c2s",
}
proxy_trusted_proxies = private_networks
-- Marks proxied s2s connections as secure. Only relevant for s2s carried
-- through mod_net_proxy; the direct 5269 listener uses dialback instead.
proxy_secure_in = true

-- Only the PROXY protocol listener is used for native clients; there is no
-- direct plaintext XMPP listener.
c2s_ports = {}
-- Server-to-server federation for the private overlay.
s2s_ports = { 5269 }
http_ports = { 5280 }
http_interfaces = { "*" }
https_ports = {}
http_host = domain
http_external_url = scheme .. "://" .. public_domain .. "/prosody/"

modules_enabled = {
    "net_proxy",
    "disco",
    "roster",
    "saslauth",
    "dialback",
    "blocklist",
    "bookmarks",
    "carbons",
    "limits",
    "pep",
    "private",
    "smacks",
    "vcard4",
    "vcard_legacy",
    "account_activity",
    "cloud_notify",
    "csi_simple",
    "ping",
    "time",
    "uptime",
    "version",
    "admin_adhoc",
    "admin_shell",
    "bosh",
    "websocket",
    "http_altconnect",
    "mam",
}

modules_disabled = {
    "register",
    "invites_register",
}

limits = {
    c2s = { rate = "10kb/s" },
    s2sin = { rate = "30kb/s" },
}

log = {
    info = "*console",
}

VirtualHost(domain)

Component("conference." .. domain) "muc"
modules_enabled = { "muc_mam" }
muc_room_default_public_jids = false

Component("upload." .. domain) "http_file_share"
http_file_share_size_limit = 64 * 1024 * 1024
http_paths = { file_share = "/share" }
