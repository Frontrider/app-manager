import importlib.util
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError


SOURCE = Path(__file__).resolve().parents[2] / "apps/singleton/books/komga"


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, SOURCE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


oidc = load("komga_oidc", "oidc-setup.py")
library = load("komga_library", "library-setup.py")


class KomgaSetupTest(unittest.TestCase):
    def test_registration_and_private_profile(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            global_env = root / "global_env"
            config = root / "config"
            (global_env / "oidc/secrets").mkdir(parents=True)
            config.mkdir()
            (global_env / "DOMAIN").write_text("localhost.example.test\n")
            (global_env / "oidc/secrets/localhost-komga").write_text("a" * 64)
            with patch.object(oidc.os, "chown") as chown:
                oidc.configure(global_env, config)
            self.assertEqual(
                (global_env / "oidc/requests/localhost-komga").read_text(),
                "https://localhost.example.test/books/login/oauth2/code/authelia\n",
            )
            profile = config / "application-localhost.yml"
            self.assertEqual(profile.stat().st_mode & 0o777, 0o600)
            client = json.loads(profile.read_text())["spring"]["security"]["oauth2"]["client"]
            self.assertEqual(client["registration"]["authelia"]["client-secret"], "a" * 64)
            self.assertEqual(client["provider"]["authelia"]["issuer-uri"], "https://localhost.example.test/auth")
            chown.assert_called_once()
            self.assertEqual(chown.call_args.args[1:], (1000, 1000))

    def test_changed_registration_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "oidc/requests").mkdir(parents=True)
            (root / "DOMAIN").write_text("localhost.example.test")
            (root / "oidc/requests/localhost-komga").write_text("https://old.example.test/callback\n")
            with self.assertRaisesRegex(ValueError, "different redirect URIs"):
                oidc.configure(root, root)

    def test_reader_and_admin_provisioning(self):
        with patch.object(library, "api", return_value=[]) as api:
            library.synchronize_users({"reader@example.test": False, "admin@example.test": True})
        calls = api.call_args_list
        self.assertEqual(calls[1].args[2]["roles"], ["FILE_DOWNLOAD", "PAGE_STREAMING", "ADMIN"])
        self.assertEqual(calls[2].args[2]["roles"], ["FILE_DOWNLOAD", "PAGE_STREAMING"])
        self.assertTrue(calls[1].args[2]["sharedLibraries"]["all"])

    def test_admin_revocation_and_unchanged_reader(self):
        existing = [
            {"id": "reader", "email": "reader@example.test",
             "roles": ["USER", "FILE_DOWNLOAD", "PAGE_STREAMING"], "sharedAllLibraries": True},
            {"id": "former", "email": "former@example.test",
             "roles": ["USER", "FILE_DOWNLOAD", "PAGE_STREAMING", "ADMIN"], "sharedAllLibraries": True},
            {"id": "bootstrap", "email": "localhost-admin@example.invalid",
             "roles": ["USER", "ADMIN"], "sharedAllLibraries": True},
        ]
        with patch.object(library, "api", return_value=existing) as api:
            library.synchronize_users({"reader@example.test": False, "former@example.test": False})
        self.assertEqual(api.call_count, 2)
        self.assertEqual(api.call_args.args[:2], ("PATCH", "/users/former"))
        self.assertNotIn("ADMIN", api.call_args.args[2]["roles"])

    def test_rejected_account_does_not_block_other_admin_promotions(self):
        existing = [
            {"id": "admin", "email": "admin@example.test",
             "roles": ["USER", "FILE_DOWNLOAD", "PAGE_STREAMING"], "sharedAllLibraries": True},
        ]

        def respond(method, path, body=None, **kwargs):
            if method == "GET":
                return existing
            if method == "POST":
                raise RuntimeError("Komga API POST /api/v2/users returned HTTP 400")

        with patch.object(library, "api", side_effect=respond) as api:
            with self.assertRaisesRegex(RuntimeError, "permission synchronization failed"):
                library.synchronize_users({"reader@example.test": False, "admin@example.test": True})
        self.assertEqual(api.call_args_list[1].args[:2], ("PATCH", "/users/admin"))
        self.assertIn("ADMIN", api.call_args_list[1].args[2]["roles"])

    def test_rejected_directory_admin_does_not_block_valid_admin(self):
        existing = [
            {"id": "admin", "email": "admin@example.test",
             "roles": ["USER", "FILE_DOWNLOAD", "PAGE_STREAMING"], "sharedAllLibraries": True},
        ]

        def respond(method, path, body=None, **kwargs):
            if method == "GET":
                return existing
            if method == "POST":
                raise RuntimeError("Komga API POST /api/v2/users returned HTTP 400")

        with patch.object(library, "api", side_effect=respond) as api:
            with self.assertRaisesRegex(RuntimeError, "permission synchronization failed"):
                library.synchronize_users({"service@localhost": True, "admin@example.test": True})
        self.assertEqual(api.call_args_list[-1].args[:2], ("PATCH", "/users/admin"))
        self.assertIn("ADMIN", api.call_args_list[-1].args[2]["roles"])

    def test_api_reports_failure_without_request_password(self):
        error = HTTPError("http://komga", 400, "Bad Request", {}, io.BytesIO(b"Invalid password secret-value"))
        with patch.object(library, "urlopen", side_effect=error):
            with self.assertRaises(RuntimeError) as raised:
                library.api("POST", "/users", {"password": "secret-value"}, version="v2")
        self.assertIn("POST /api/v2/users returned HTTP 400", str(raised.exception))
        self.assertNotIn("secret-value", str(raised.exception))


if __name__ == "__main__":
    unittest.main()
