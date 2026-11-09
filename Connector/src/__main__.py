"""Tower connector : python3 tower-connector.pyz [config.json]"""

import asyncio
import json
import os
import platform
import random
import subprocess
import sys
import zipfile
from pathlib import Path
from socket import gethostname

import websockets

EMBEDDED_CONFIG = "tower_config.json"
REQUIRED_KEYS = ("tower_url", "client_id", "secret")
MAX_BACKOFF_SECONDS = 60
# A connection that lasted this long was stable : the next failure starts a new backoff
STABLE_AFTER_SECONDS = 60
# SMBIOS chassis types
LAPTOP_CHASSIS = {8, 9, 10, 14, 31, 32}
DESKTOP_CHASSIS = {3, 4, 5, 6, 7, 13, 15, 16, 35, 36}
MOBILE_CHASSIS = {11, 30}
# SYSTEM_POWER_STATUS.BatteryFlag
WINDOWS_NO_BATTERY = 128
WINDOWS_BATTERY_UNKNOWN = 255


class Fatal(Exception):
    """Tower refused this connector : retrying would not help."""


def read_config() -> dict:
    if len(sys.argv) > 1:
        return json.loads(Path(sys.argv[1]).read_text())

    try:
        with zipfile.ZipFile(sys.argv[0]) as archive:
            return json.loads(archive.read(EMBEDDED_CONFIG))
    except (KeyError, zipfile.BadZipFile, FileNotFoundError):
        raise Fatal("no configuration: python3 tower-connector.pyz <config.json>")


def handle(message: dict) -> None:
    if "error" in message:
        raise Fatal(f"refused by Tower: {message['error']}")

    match message.get("type"):
        case "connection":
            print(f"Connected to Tower, connection {message['uuid']}", flush=True)
        case "disconnect":
            raise Fatal(f"disconnected by Tower: {message.get('reason', 'unknown')}")
        case _:
            print(f"Received: {message}", flush=True)


def read_device_type() -> str:
    if hasattr(sys, "getandroidapilevel") or "TERMUX_VERSION" in os.environ:
        return "mobile"

    if sys.platform == "darwin":
        power = subprocess.run(["pmset", "-g", "batt"], capture_output=True, text=True, timeout=5).stdout
        return "laptop" if "InternalBattery" in power else "desktop"

    if sys.platform == "win32":
        import ctypes

        status = ctypes.create_string_buffer(12)  # SYSTEM_POWER_STATUS
        if not ctypes.windll.kernel32.GetSystemPowerStatus(status) or status.raw[1] == WINDOWS_BATTERY_UNKNOWN:
            return "unknown"
        return "desktop" if status.raw[1] == WINDOWS_NO_BATTERY else "laptop"

    chassis = int(Path("/sys/class/dmi/id/chassis_type").read_text())
    if chassis in LAPTOP_CHASSIS:
        return "laptop"
    if chassis in DESKTOP_CHASSIS:
        return "desktop"
    return "mobile" if chassis in MOBILE_CHASSIS else "unknown"


def device_type() -> str:
    try:
        return read_device_type()
    except (OSError, ValueError, subprocess.SubprocessError):
        return "unknown"


async def session(config: dict, kind: str) -> None:
    headers = {
        "X-Tower-Client-Id": config["client_id"],
        "X-Tower-Secret": config["secret"],
        "X-Tower-Hostname": gethostname(),
        "X-Tower-Device-Type": kind,
    }
    user_agent = f"tower-connector ({platform.system()} {platform.release()}; {platform.machine()}; Python {platform.python_version()})"

    async with websockets.connect(config["tower_url"], additional_headers=headers, user_agent_header=user_agent) as socket:
        async for frame in socket:
            handle(json.loads(frame))


async def run(config: dict) -> None:
    loop = asyncio.get_running_loop()
    kind = device_type()
    backoff = 1

    while True:
        started_at = loop.time()
        try:
            await session(config, kind)
            reason = "connection closed"
        except (OSError, websockets.WebSocketException) as error:
            reason = str(error) or type(error).__name__

        if loop.time() - started_at >= STABLE_AFTER_SECONDS:
            backoff = 1

        delay = backoff + random.uniform(0, backoff / 2)
        print(f"{reason}, retrying in {delay:.0f}s", file=sys.stderr, flush=True)
        await asyncio.sleep(delay)
        backoff = min(backoff * 2, MAX_BACKOFF_SECONDS)


def main() -> int:
    try:
        config = read_config()
        missing = [key for key in REQUIRED_KEYS if not config.get(key)]
        if missing:
            raise Fatal(f"missing in configuration: {', '.join(missing)}")

        asyncio.run(run(config))
    except Fatal as error:
        print(error, file=sys.stderr)
        return 1
    except (OSError, json.JSONDecodeError) as error:
        print(f"cannot read configuration: {error}", file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        return 0

    return 0


sys.exit(main())
