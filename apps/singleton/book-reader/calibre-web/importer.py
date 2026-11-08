import hashlib
from pathlib import Path
import sqlite3
import subprocess
import time

from setup import application_config, directory_users, LIBRARY, synchronize_users


INCOMING = Path("/Data/books")
FORMATS = {".epub", ".pdf", ".mobi", ".azw", ".azw3", ".fb2", ".rtf", ".txt", ".cbz", ".cbr"}


def snapshot(directory):
    files = {}
    for path in directory.rglob("*"):
        if path.is_symlink() or not path.is_file() or path.suffix.lower() not in FORMATS:
            continue
        if not path.resolve().is_relative_to(directory.resolve()):
            continue
        stat = path.stat()
        if stat.st_size:
            files[path] = (stat.st_size, stat.st_mtime_ns)
    return files


def import_stable(previous, current, database):
    for path, signature in current.items():
        if previous.get(path) != signature:
            continue
        with path.open("rb") as stream:
            digest = hashlib.file_digest(stream, "sha256").hexdigest()
        if database.execute("SELECT 1 FROM imported WHERE digest = ?", (digest,)).fetchone():
            continue
        stat = path.stat()
        if (stat.st_size, stat.st_mtime_ns) != signature:
            continue
        subprocess.run(["calibredb", "add", "--with-library", LIBRARY, str(path)], check=True)
        database.execute("INSERT INTO imported (digest) VALUES (?)", (digest,))
        database.commit()
        print("Book imported into Calibre library.", flush=True)


def main():
    _, ub = application_config()
    with sqlite3.connect("/config/imported.db") as database:
        database.execute("CREATE TABLE IF NOT EXISTS imported (digest TEXT PRIMARY KEY)")
        previous = {}
        while True:
            current = snapshot(INCOMING)
            import_stable(previous, current, database)
            synchronize_users(directory_users(), ub)
            previous = current
            time.sleep(30)


if __name__ == "__main__":
    main()
