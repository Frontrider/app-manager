import json
import os
from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class GameStorageTest(unittest.TestCase):
    def test_every_game_uses_shared_storage(self):
        files = sorted((ROOT / "apps").glob("*/games/*/docker-compose.yaml"))
        self.assertTrue(files)
        for file in files:
            with self.subTest(game=file.parent.name):
                environment = os.environ.copy()
                environment["DATA_VOLUME"] = "localhost-game-storage-validation"
                config = json.loads(subprocess.check_output(
                    ["docker", "compose", "--env-file", str(file.parent / "env"),
                     "-f", str(file), "config", "--format", "json"],
                    env=environment,
                    text=True,
                ))
                self.assertEqual(["data"], list(config["volumes"]))
                self.assertTrue(config["volumes"]["data"]["external"])
                self.assertEqual(
                    "localhost-game-storage-validation", config["volumes"]["data"]["name"]
                )
                game = config["services"][file.parent.name]
                self.assertEqual(
                    "service_completed_successfully",
                    game["depends_on"]["data-init"]["condition"],
                )
                mounts = [mount for mount in game["volumes"] if mount["type"] == "volume"]
                self.assertTrue(any(
                    mount["source"] == "data" and mount["target"] == "/Data"
                    for mount in mounts
                ))
                storage = [mount for mount in mounts if mount["target"] != "/Data"]
                self.assertTrue(storage)
                for mount in storage:
                    self.assertEqual("data", mount["source"])
                    subpath = mount["volume"]["subpath"]
                    expected = "games/" + file.parent.name
                    self.assertTrue(subpath == expected or subpath.startswith(expected + "/"))
                    self.assertTrue(mount["volume"]["nocopy"])
                    self.assertIn(
                        "/data/" + subpath,
                        config["services"]["data-init"]["command"][-1],
                    )
                if file.parent.name == "valheim":
                    self.assertEqual("1000", game["environment"]["PUID"])
                    self.assertEqual("1000", game["environment"]["PGID"])
                elif file.parent.name in ("minecraft", "hytale"):
                    self.assertEqual("1000", game["environment"]["UID"])
                    self.assertEqual("1000", game["environment"]["GID"])
                else:
                    self.assertEqual("1000:1000", game["user"])


if __name__ == "__main__":
    unittest.main()
