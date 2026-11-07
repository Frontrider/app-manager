import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch, MagicMock
from urllib.error import HTTPError
import tempfile
import json


def load(name, path):
    spec = importlib.util.spec_from_file_location(
        name, Path(__file__).resolve().parents[2] / path
    )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


jellyfin = load("jellyfin_setup", "apps/singleton/video/jelylfin/library-setup.py")
komga = load("komga_setup", "apps/singleton/books/komga/library-setup.py")


class LibrarySetupTest(unittest.TestCase):
    def setUp(self):
        for module in (komga, jellyfin):
            bootstrap = patch.object(module, "bootstrap")
            bootstrap.start()
            self.addCleanup(bootstrap.stop)

    def test_jellyfin_creates_and_scans(self):
        with patch.object(jellyfin, "request", side_effect=[[], None]) as request:
            jellyfin.setup()
        create = request.call_args_list[1]
        self.assertIn("paths=%2Fdata%2Fvideos", create.args[1])
        self.assertIn("refreshLibrary=true", create.args[1])
        self.assertTrue(create.args[2]["LibraryOptions"]["EnableRealtimeMonitor"])

    def test_jellyfin_existing_library_is_not_duplicated(self):
        with patch.object(jellyfin, "request", side_effect=[
            [{"Locations": ["/data/videos"], "Name": "Custom"}], None
        ]) as request:
            jellyfin.setup()
        self.assertEqual(request.call_args.args[1], "/Library/Refresh")

    def test_komga_creates_and_scans(self):
        with patch.object(komga, "request", side_effect=[[], {"id": "123"}, None]) as request:
            komga.setup()
        self.assertEqual(request.call_args_list[1].args[2]["root"], "/data/books")
        self.assertTrue(request.call_args_list[1].args[2]["scanOnStartup"])
        self.assertEqual(request.call_args.args[1], "/123/scan")

    def test_komga_existing_library_is_not_duplicated(self):
        with patch.object(komga, "request", side_effect=[
            [{"root": "/data/books/", "name": "Custom", "id": "123"}], None
        ]) as request:
            komga.setup()
        self.assertEqual(request.call_count, 2)

    def test_invalid_keys_are_not_retried(self):
        for module in (komga, jellyfin):
            with patch.object(module, "request", side_effect=HTTPError(
                "http://server", 401, "Unauthorized", {}, None
            )) as request, self.assertRaises(HTTPError):
                module.setup()
            self.assertEqual(request.call_count, 1)

    def test_name_conflicts_are_not_overwritten(self):
        with patch.object(komga, "request", return_value=[
            {"root": "/different", "name": "Books", "id": "123"}
        ]), self.assertRaisesRegex(ValueError, "different path"):
            komga.setup()

    def test_requests_use_service_urls_and_key_headers(self):
        for module, key, header, url in (
            (komga, "KOMGA_API_KEY", "X-api-key", "http://komga:25600/books/api/v1/libraries"),
            (jellyfin, "JELLYFIN_API_KEY", "X-emby-token", "http://jellyfin:8096/jellyfin"),
        ):
            response = MagicMock()
            response.__enter__.return_value.read.return_value = b"[]"
            with patch.dict(module.os.environ, {key: "test-key"}), patch.object(
                module, "urlopen", return_value=response
            ) as urlopen, patch.object(module, "auth_headers", {header: "test-key"}):
                self.assertEqual(module.request("GET", ""), [])
            req = urlopen.call_args.args[0]
            self.assertEqual(req.full_url, url)
            self.assertEqual(req.get_header(header), "test-key")


class BootstrapTest(unittest.TestCase):
    def test_komga_claims_and_persists_credentials(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / "credentials.json"
            with patch.dict(komga.os.environ, {}, clear=True), patch.object(
                komga, "Path", return_value=file
            ), patch.object(komga, "api", side_effect=[{"isClaimed": False}, {}]) as api:
                komga.bootstrap()
            self.assertEqual(file.stat().st_mode & 0o777, 0o600)
            credentials = json.loads(file.read_text())
            self.assertEqual(api.call_args.kwargs["headers"]["X-Komga-Password"], credentials["password"])
            with patch.dict(komga.os.environ, {}, clear=True), patch.object(
                komga, "Path", return_value=file
            ), patch.object(komga, "api", return_value={"isClaimed": True}) as api:
                komga.bootstrap()
            self.assertEqual(api.call_count, 1)
            self.assertTrue(komga.auth_headers["Authorization"].startswith("Basic "))

    def test_jellyfin_bootstraps_and_reuses_credentials(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / "credentials.json"
            error = HTTPError("http://server", 401, "Unauthorized", {}, None)
            with patch.dict(jellyfin.os.environ, {}, clear=True), patch.object(
                jellyfin, "Path", return_value=file
            ), patch.object(jellyfin, "request", side_effect=[
                {"StartupWizardCompleted": False}, error, {}, None, {"AccessToken": "first"}, None,
                {"AccessToken": "token"}
            ]) as request:
                jellyfin.bootstrap()
            self.assertEqual(file.stat().st_mode & 0o777, 0o600)
            self.assertEqual(request.call_args_list[-2].args[1], "/Startup/Complete")
            self.assertIn('Token="token"', jellyfin.auth_headers["Authorization"])
            with patch.dict(jellyfin.os.environ, {}, clear=True), patch.object(
                jellyfin, "Path", return_value=file
            ), patch.object(jellyfin, "request", side_effect=[
                {"StartupWizardCompleted": True}, {"AccessToken": "new"}
            ]) as request:
                jellyfin.bootstrap()
            self.assertEqual(request.call_count, 2)

    def test_existing_installations_without_credentials_are_not_modified(self):
        for module, method, status in (
            (komga, "api", {"isClaimed": True}),
            (jellyfin, "request", {"StartupWizardCompleted": True}),
        ):
            with tempfile.TemporaryDirectory() as directory:
                file = Path(directory) / "missing.json"
                with patch.dict(module.os.environ, {}, clear=True), patch.object(
                    module, "Path", return_value=file
                ), patch.object(module, method, return_value=status) as request, self.assertRaisesRegex(
                    ValueError, "No accounts were changed"
                ):
                    module.bootstrap()
                self.assertEqual(request.call_count, 1)
                self.assertFalse(file.exists())


if __name__ == "__main__":
    unittest.main()
