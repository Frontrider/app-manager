import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch
from xml.etree import ElementTree


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "jellyfin_startup", ROOT / "apps/singleton/video/jelylfin/library-setup.py"
)
jellyfin = importlib.util.module_from_spec(spec)
spec.loader.exec_module(jellyfin)


class LibraryConfigTest(unittest.TestCase):
    def test_existing_library_settings_are_repaired(self):
        library = {
            "Name": "Custom", "Locations": ["/data/videos"], "ItemId": "123",
            "LibraryOptions": {"EnableRealtimeMonitor": False, "CustomSetting": True},
        }
        with patch.object(jellyfin, "bootstrap"), patch.object(
            jellyfin, "request", side_effect=[[library], None, None]
        ) as request:
            jellyfin.setup()
        self.assertEqual(request.call_args_list[1].args, (
            "POST", "/Library/VirtualFolders/LibraryOptions", {
                "Id": "123",
                "LibraryOptions": {"EnableRealtimeMonitor": True, "CustomSetting": True},
            },
        ))
        self.assertEqual(request.call_args.args, ("POST", "/Library/Refresh"))

    def test_managed_library_path_is_repaired_without_recreating_library(self):
        library = {
            "Name": "Videos", "Locations": ["/wrong"], "ItemId": "123",
            "LibraryOptions": {},
        }
        with patch.object(jellyfin, "bootstrap"), patch.object(
            jellyfin, "request", side_effect=[[
                {"Name": "Custom", "Locations": ["/data/videos"], "ItemId": "456", "LibraryOptions": {}},
                library,
            ], None, None, None, None]
        ) as request:
            jellyfin.setup()
        self.assertEqual(request.call_args_list[1].args, (
            "POST", "/Library/VirtualFolders/Paths", {"Name": "Videos", "Path": "/data/videos"}
        ))
        self.assertEqual(request.call_args_list[2].args, (
            "DELETE", "/Library/VirtualFolders/Paths?name=Videos&path=%2Fwrong"
        ))


@unittest.skipUnless(os.environ.get("JELLYFIN_STARTUP_IMAGE"), "Set JELLYFIN_STARTUP_IMAGE to an isolated test image.")
class JellyfinBootTest(unittest.TestCase):
    def docker(self, *args):
        return subprocess.run(["docker", *args], check=True, capture_output=True, text=True).stdout

    def test_restart_reapplies_config_and_library_settings(self):
        image = os.environ["JELLYFIN_STARTUP_IMAGE"]
        name = f"jellyfin-startup-test-{os.getpid()}"
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "config"
            videos = Path(directory) / "videos"
            environment = Path(directory) / "global-env"
            environment.mkdir()
            password_file = environment / "lldap_service_password"
            password_file.write_text("test&<password>")
            config.mkdir(mode=0o777)
            videos.mkdir(mode=0o777)
            # Use a private, disposable volume layout and run as the test process owner.
            user = f"{os.getuid()}:{os.getgid()}"
            if os.getuid() == 0:
                self.skipTest("Run as a non-root user; the application runs non-root.")
            try:
                self.docker(
                    "run", "-d", "--name", name, "--user", user,
                    "-e", "LDAP_HOST=core",
                    "-e", "JELLYFIN_CACHE_DIR=/config/cache",
                    "-v", f"{config}:/config", "-v", f"{videos}:/data/videos:ro",
                    "-v", f"{environment}:/global-env:ro", image,
                )
                self.wait_ready(name)
                self.assert_effective_ldap_config(name)
                credentials = (config / "localhost-bootstrap.json").read_bytes()
                self.docker(
                    "exec", "-e", "JELLYFIN_URL=http://127.0.0.1:8096/jellyfin",
                    name, "python3", "-c",
                    'import runpy; m = runpy.run_path("/library-setup.py"); m["bootstrap"](); '
                    'library = m["request"]("GET", "/Library/VirtualFolders")[0]; '
                    'm["request"]("POST", "/Library/VirtualFolders/Paths", {"Name": "Videos", "Path": "/config/cache"}); '
                    'm["request"]("DELETE", "/Library/VirtualFolders/Paths?name=Videos&path=%2Fdata%2Fvideos"); '
                    'library = m["request"]("GET", "/Library/VirtualFolders")[0]; '
                    'options = library["LibraryOptions"]; options["EnableRealtimeMonitor"] = False; '
                    'm["request"]("POST", "/Library/VirtualFolders/LibraryOptions", {"Id": library["ItemId"], "LibraryOptions": options})',
                )
                self.docker("stop", name)
                plugin = config / "plugins/configurations/LDAP-Auth.xml"
                plugin.write_text(
                    "<PluginConfiguration><LdapServer>ldap-server.contoso.com</LdapServer>"
                    "<LdapPort>389</LdapPort></PluginConfiguration>"
                )
                network = config / "config/network.xml"
                tree = ElementTree.parse(network)
                tree.getroot().remove(tree.getroot().find("BaseUrl"))
                tree.write(network)
                self.docker("start", name)
                self.wait_ready(name, successes=2)
                self.assert_effective_ldap_config(name)
                self.assertEqual(credentials, (config / "localhost-bootstrap.json").read_bytes())
                settings = ElementTree.parse(plugin).getroot()
                self.assertEqual("core", settings.findtext("LdapServer"))
                self.assertEqual("3890", settings.findtext("LdapPort"))
                self.assertEqual("test&<password>", settings.findtext("LdapBindPassword"))
                self.assertEqual("/jellyfin", ElementTree.parse(network).findtext("BaseUrl"))
                libraries = json.loads(self.docker(
                    "exec", "-e", "JELLYFIN_URL=http://127.0.0.1:8096/jellyfin",
                    name, "python3", "-c",
                    'import json, runpy; m = runpy.run_path("/library-setup.py"); m["bootstrap"](); '
                    'print(json.dumps(m["request"]("GET", "/Library/VirtualFolders")))',
                ))
                self.assertEqual(1, len(libraries))
                self.assertEqual(["/data/videos"], libraries[0]["Locations"])
                self.assertTrue(libraries[0]["LibraryOptions"]["EnableRealtimeMonitor"])
                password_file.write_text("rotated&<password>")
                self.docker("restart", name)
                self.wait_ready(name, successes=3)
                self.assert_effective_ldap_config(name, password="rotated&<password>")
            finally:
                self.docker("rm", "-f", name)

    def test_missing_or_empty_service_password_stops_startup(self):
        image = os.environ["JELLYFIN_STARTUP_IMAGE"]
        with tempfile.TemporaryDirectory() as directory:
            for empty in (False, True):
                if empty:
                    (Path(directory) / "lldap_service_password").write_text("")
                result = subprocess.run([
                    "docker", "run", "--rm", "-v", f"{directory}:/global-env:ro", image,
                ], capture_output=True, text=True)
                self.assertNotEqual(0, result.returncode)
                self.assertIn("LLDAP service password file", result.stderr)
                self.assertIn("empty" if empty else "missing or unreadable", result.stderr)

    def assert_effective_ldap_config(self, name, password="test&<password>"):
        settings = json.loads(self.docker(
            "exec", "-e", "JELLYFIN_URL=http://127.0.0.1:8096/jellyfin",
            name, "python3", "-c",
            'import json, runpy; m = runpy.run_path("/library-setup.py"); m["bootstrap"](); '
            'plugin = next(p for p in m["request"]("GET", "/Plugins") if p["Name"] == "LDAP-Auth"); '
            'settings = m["request"]("GET", "/Plugins/" + plugin["Id"] + "/Configuration"); '
            'print(json.dumps({key: settings[key] for key in ("LdapServer", "LdapPort", "LdapBindUser", "LdapBindPassword")}))',
        ))
        self.assertEqual("core", settings["LdapServer"])
        self.assertEqual(3890, settings["LdapPort"])
        self.assertEqual("uid=service,ou=people,dc=example,dc=com", settings["LdapBindUser"])
        self.assertEqual(password, settings["LdapBindPassword"])

    def wait_ready(self, name, successes=1):
        deadline = time.monotonic() + 180
        while time.monotonic() < deadline:
            result = subprocess.run(
                ["docker", "logs", name], capture_output=True, text=True, check=True
            )
            # Require successful API reconciliation, not merely an HTTP listener.
            if result.stdout.count("Videos library configured and scan requested.") >= successes:
                return
            if self.docker("inspect", "-f", "{{.State.Running}}", name).strip() != "true":
                self.fail(result.stdout + result.stderr)
            time.sleep(1)
        self.fail(self.docker("logs", name))


@unittest.skipUnless(os.environ.get("SFTPGO_STARTUP_IMAGE"), "Set SFTPGO_STARTUP_IMAGE to an isolated test image.")
class SftpgoBootTest(unittest.TestCase):
    def test_restart_restores_managed_group(self):
        image = os.environ["SFTPGO_STARTUP_IMAGE"]
        name = f"sftpgo-startup-test-{os.getpid()}"
        compose = subprocess.run([
            "docker", "compose", "-f",
            str(ROOT / "apps/singleton/file-manager/sftpgo/docker-compose.yaml"),
            "config", "--format", "json",
        ], check=True, capture_output=True, text=True)
        environment = json.loads(compose.stdout)["services"]["sftpgo"]["environment"]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "lldap_service_password").write_text("validation-password")
            args = ["docker", "run", "-d", "--name", name, "--network", "none",
                    "--user", f"{os.getuid()}:{os.getgid()}",
                    "-v", f"{root}:/var/lib/sftpgo",
                    "-v", f"{root}:/var/environment/global-env:ro"]
            for key, value in environment.items():
                args.extend(["-e", f"{key}={value}"])
            try:
                subprocess.run([*args, image], check=True, capture_output=True)
                self.wait_ready(name)
                password = (root / "bootstrap-admin-password").read_bytes()
                token = self.api(name, "/token", credentials="localhost-bootstrap:" + password.decode())
                token = token["access_token"]
                group = self.api(name, "/groups/lldap_admin", token=token)
                group["user_settings"]["home_dir"] = "/wrong"
                self.api(name, "/groups/lldap_admin", token=token, body=group)
                subprocess.run(["docker", "restart", name], check=True, capture_output=True)
                self.wait_ready(name)
                token = self.api(name, "/token", credentials="localhost-bootstrap:" + password.decode())["access_token"]
                group = self.api(name, "/groups/lldap_admin", token=token)
                self.assertEqual("/data", group["user_settings"]["home_dir"])
                self.assertEqual(password, (root / "bootstrap-admin-password").read_bytes())
            finally:
                subprocess.run(["docker", "rm", "-f", name], check=True, capture_output=True)

    def api(self, name, path, token=None, credentials=None, body=None):
        args = ["docker", "exec", name, "curl", "-fsS"]
        if token:
            args.extend(["-H", "Authorization: Bearer " + token])
        if credentials:
            args.extend(["-u", credentials])
        if body:
            args.extend(["-X", "PUT", "-H", "Content-Type: application/json", "-d", json.dumps(body)])
        result = subprocess.run(
            [*args, "http://127.0.0.1:8080/api/v2" + path],
            check=True, capture_output=True, text=True,
        )
        return json.loads(result.stdout) if result.stdout else None

    def wait_ready(self, name):
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            result = subprocess.run([
                "docker", "exec", name, "curl", "-fsS",
                "http://127.0.0.1:8080/files/web/client/login",
            ], capture_output=True)
            if result.returncode == 0:
                return
            time.sleep(1)
        logs = subprocess.run(["docker", "logs", name], capture_output=True, text=True)
        self.fail(logs.stdout + logs.stderr)


@unittest.skipUnless(os.environ.get("SYNCTHING_STARTUP_IMAGE"), "Set SYNCTHING_STARTUP_IMAGE to test Syncthing.")
class SyncthingBootTest(unittest.TestCase):
    def test_boot_restores_missing_gui_settings_and_preserves_identity(self):
        image = os.environ["SYNCTHING_STARTUP_IMAGE"]
        name = f"syncthing-startup-test-{os.getpid()}"
        compose = subprocess.run([
            "docker", "compose", "-f",
            str(ROOT / "apps/standard/sharing/syncthing/docker-compose.yaml"),
            "config", "--format", "json",
        ], check=True, capture_output=True, text=True)
        service = json.loads(compose.stdout)["services"]["syncthing"]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            args = ["docker", "run", "-d", "--name", name, "--network", "none",
                    "-v", f"{root}:/var/syncthing"]
            for key, value in service["environment"].items():
                args.extend(["-e", f"{key}={value}"])
            try:
                subprocess.run([
                    *args, "--entrypoint", service["entrypoint"][0], image,
                    *(argument.replace("$$", "$") for argument in service["entrypoint"][1:]),
                ], check=True, capture_output=True)
                self.wait_ready(name)
                subprocess.run(["docker", "stop", name], check=True, capture_output=True)
                identity = (root / "cert.pem").read_bytes()
                config = root / "config.xml"
                tree = ElementTree.parse(config)
                gui = tree.getroot().find("gui")
                gui.remove(gui.find("address"))
                gui.remove(gui.find("insecureAdminAccess"))
                tree.write(config)
                subprocess.run(["docker", "start", name], check=True, capture_output=True)
                self.wait_ready(name)
                gui = ElementTree.parse(config).getroot().find("gui")
                self.assertEqual("0.0.0.0:8384", gui.findtext("address"))
                self.assertEqual("true", gui.findtext("insecureAdminAccess"))
                self.assertEqual(identity, (root / "cert.pem").read_bytes())
            finally:
                subprocess.run(["docker", "rm", "-f", name], check=True, capture_output=True)

    def wait_ready(self, name):
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            result = subprocess.run([
                "docker", "exec", name, "wget", "-q", "-O", "/dev/null",
                "http://127.0.0.1:8384/",
            ], capture_output=True)
            if result.returncode == 0:
                return
            time.sleep(1)
        logs = subprocess.run(["docker", "logs", name], capture_output=True, text=True)
        self.fail(logs.stdout + logs.stderr)


if __name__ == "__main__":
    unittest.main()
