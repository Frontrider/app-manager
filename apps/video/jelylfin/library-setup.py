import json
import os
import sys
import time
import secrets
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


auth_headers = {}


def request(method, path, body=None, headers=None):
    req = Request(
        os.environ.get("JELLYFIN_URL", "http://jellyfin:8096/jellyfin") + path,
        data=json.dumps(body).encode() if body is not None else None,
        headers={"Content-Type": "application/json", **(headers if headers is not None else auth_headers)},
        method=method,
    )
    try:
        with urlopen(req, timeout=10) as response:
            content = response.read()
            return json.loads(content) if content else None
    except HTTPError as error:
        error.msg = f"{method} {path}: {error.reason}"
        raise


def bootstrap():
    global auth_headers
    deadline = time.monotonic() + 300
    while True:
        try:
            status = request("GET", "/System/Info/Public", headers={})
            break
        except (HTTPError, URLError) as error:
            if isinstance(error, HTTPError) and error.code not in (502, 503, 504):
                raise
            if time.monotonic() >= deadline:
                raise TimeoutError("Jellyfin did not become ready within 300 seconds.") from error
            print("Waiting for Jellyfin...", flush=True)
            time.sleep(5)
    key = os.environ.get("JELLYFIN_API_KEY")
    if key:
        auth_headers = {"X-Emby-Token": key}
        return
    file = Path("/config/localhost-bootstrap.json")
    if file.exists():
        credentials = json.loads(file.read_text())
    else:
        if status["StartupWizardCompleted"]:
            raise ValueError("Existing Jellyfin has no saved bootstrap credentials; provide JELLYFIN_API_KEY. No accounts were changed.")
        credentials = {"username": "localhost-admin", "password": secrets.token_urlsafe(32)}
        with open(file, "x", opener=lambda path, flags: os.open(path, flags, 0o600)) as stream:
            json.dump(credentials, stream)
            stream.flush()
            os.fsync(stream.fileno())
    client = {"Authorization": 'MediaBrowser Client="Localhost", Device="Library Setup", DeviceId="localhost-library-setup", Version="1.0"'}
    try:
        login = request("POST", "/Users/AuthenticateByName", {
            "Username": credentials["username"], "Pw": credentials["password"],
        }, headers=client)
    except HTTPError as error:
        if error.code != 401 or status["StartupWizardCompleted"]:
            raise
        request("GET", "/Startup/User", headers={})
        request("POST", "/Startup/User", {
            "Name": credentials["username"], "Password": credentials["password"],
        }, headers={})
        login = request("POST", "/Users/AuthenticateByName", {
            "Username": credentials["username"], "Pw": credentials["password"],
        }, headers=client)
    auth_headers = {"Authorization": client["Authorization"] + ', Token="' + login["AccessToken"] + '"'}
    if not status["StartupWizardCompleted"]:
        request("POST", "/Startup/Complete")
        login = request("POST", "/Users/AuthenticateByName", {
            "Username": credentials["username"], "Pw": credentials["password"],
        }, headers=client)
        auth_headers = {"Authorization": client["Authorization"] + ', Token="' + login["AccessToken"] + '"'}


def setup():
    bootstrap()
    libraries = request("GET", "/Library/VirtualFolders")
    library = next((library for library in libraries if library["Name"] == "Videos"), None)
    if library is None:
        library = next((library for library in libraries if "/data/videos" in library["Locations"]), None)
    if library is None:
        query = urlencode({"name": "Videos", "paths": "/data/videos", "refreshLibrary": "true"})
        request("POST", "/Library/VirtualFolders?" + query, {
            "LibraryOptions": {"EnableRealtimeMonitor": True}
        })
    else:
        if "/data/videos" not in library["Locations"]:
            request("POST", "/Library/VirtualFolders/Paths", {
                "Name": library["Name"], "Path": "/data/videos"
            })
        if library["Name"] == "Videos":
            for path in library["Locations"]:
                if path != "/data/videos":
                    query = urlencode({"name": library["Name"], "path": path})
                    request("DELETE", "/Library/VirtualFolders/Paths?" + query)
        options = dict(library["LibraryOptions"])
        if library["Name"] == "Videos":
            options["PathInfos"] = [{"Path": "/data/videos"}]
        options["EnableRealtimeMonitor"] = True
        request("POST", "/Library/VirtualFolders/LibraryOptions", {
            "Id": library["ItemId"], "LibraryOptions": options
        })
        request("POST", "/Library/Refresh")
    print("Videos library configured and scan requested.", flush=True)


if __name__ == "__main__":
    try:
        setup()
    except (ValueError, KeyError, OSError) as error:
        print(f"Jellyfin library setup failed: {error}", file=sys.stderr)
        sys.exit(1)
