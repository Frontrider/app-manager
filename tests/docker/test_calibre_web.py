import importlib.util
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "apps/singleton/book-reader/calibre-web"


def load(name):
    spec = importlib.util.spec_from_file_location(name, SOURCE / (name + ".py"))
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


setup = load("setup")
importer = load("importer")


class ImportTest(unittest.TestCase):
    def test_stable_files_are_imported_once_without_removing_sources(self):
        with tempfile.TemporaryDirectory() as directory, sqlite3.connect(":memory:") as database:
            root = Path(directory)
            book = root / "book.epub"
            book.write_bytes(b"book contents")
            database.execute("CREATE TABLE imported (digest TEXT PRIMARY KEY)")
            current = importer.snapshot(root)
            with patch.object(importer.subprocess, "run") as run:
                importer.import_stable({}, current, database)
                run.assert_not_called()
                importer.import_stable(current, current, database)
                run.assert_called_once_with(
                    ["calibredb", "add", "--with-library", importer.LIBRARY, str(book)], check=True
                )
                importer.import_stable(current, current, database)
                self.assertEqual(1, run.call_count)
            self.assertTrue(book.is_file())

    def test_failed_import_is_not_marked_as_imported(self):
        with tempfile.TemporaryDirectory() as directory, sqlite3.connect(":memory:") as database:
            root = Path(directory)
            (root / "book.epub").write_bytes(b"book")
            database.execute("CREATE TABLE imported (digest TEXT PRIMARY KEY)")
            current = importer.snapshot(root)
            with patch.object(importer.subprocess, "run", side_effect=RuntimeError("import failed")):
                with self.assertRaisesRegex(RuntimeError, "import failed"):
                    importer.import_stable(current, current, database)
            self.assertEqual(0, database.execute("SELECT COUNT(*) FROM imported").fetchone()[0])

    def test_changed_files_and_symlinks_are_not_imported(self):
        with tempfile.TemporaryDirectory() as directory, sqlite3.connect(":memory:") as database:
            root = Path(directory)
            book = root / "book.epub"
            book.write_bytes(b"book")
            previous = importer.snapshot(root)
            book.write_bytes(b"changed book")
            (root / "link.epub").symlink_to(book)
            current = importer.snapshot(root)
            self.assertNotIn(root / "link.epub", current)
            database.execute("CREATE TABLE imported (digest TEXT PRIMARY KEY)")
            with patch.object(importer.subprocess, "run") as run:
                importer.import_stable(previous, current, database)
                run.assert_not_called()

    def test_pinned_application_schema_and_ldap_role_mapping(self):
        if not Path("/config").is_dir() or not Path("/Data").is_dir():
            self.skipTest("Run inside the isolated Calibre-Web validation container.")
        library = Path(setup.LIBRARY)
        library.mkdir(parents=True, exist_ok=True)
        Path("/config/ldap-password").write_text("validation-password")
        with patch.object(setup, "directory_users", return_value={
            "directory-admin": ("admin@example.invalid", True),
            "directory-reader": ("reader@example.invalid", False),
        }):
            setup.setup()
        config, ub = setup.application_config()
        from cps import constants
        self.assertEqual(constants.LOGIN_LDAP, config.config_login_type)
        self.assertEqual("validation-password", config.config_ldap_serv_password_e)
        self.assertEqual(setup.LIBRARY, config.config_calibre_dir)
        config.config_ldap_provider_url = "ldap-server.contoso.com"
        config.config_ldap_port = 389
        config.config_anonbrowse = 1
        config.save()
        with patch.object(setup, "directory_users", return_value={
            "directory-admin": ("admin@example.invalid", True),
            "directory-reader": ("reader@example.invalid", False),
        }):
            setup.setup()
        config, ub = setup.application_config()
        self.assertEqual("core", config.config_ldap_provider_url)
        self.assertEqual(3890, config.config_ldap_port)
        self.assertEqual(0, config.config_anonbrowse)
        admin = ub.session.query(ub.User).filter_by(name="directory-admin").one()
        reader = ub.session.query(ub.User).filter_by(name="directory-reader").one()
        self.assertTrue(admin.role_admin())
        self.assertFalse(reader.role_admin())
        self.assertTrue(reader.role_viewer())
        self.assertEqual("", admin.password)
        setup.synchronize_users({
            "directory-admin": ("admin@example.invalid", False),
            "directory-reader": ("reader@example.invalid", True),
        }, ub)
        self.assertFalse(admin.role_admin())
        self.assertTrue(reader.role_admin())
        self.assertTrue((library / "metadata.db").is_file())


if __name__ == "__main__":
    if sys.argv[1:] == ["--serve"]:
        with patch.object(setup, "directory_users", return_value={
            "directory-admin": ("admin@example.invalid", True),
            "directory-reader": ("reader@example.invalid", False),
        }):
            sys.argv = ["setup.py", "--serve"]
            setup.main()
    else:
        unittest.main()
