"""Tower connector : python3 tower-connector.pyz [config.json] [--handler "<command>" | --handler-file handlers.json] [--no-color]

Each event Tower sends is given to the handler : the message as JSON on its standard input,
TOWER_EVENT, TOWER_EVENT_ID, TOWER_DELIVERY and TOWER_WORKSPACE in its environment. Its exit code
and output are sent back to Tower, in the history of the event.

Handlers map event patterns to one command or a list of commands, or to an object choosing the
answer sent to Tower and the next events to trigger :
    {"task.*": "notify-send Tower {payload.title}", "deploy.*": ["git pull", "make deploy"],
     "backup.run": {"run": "backup.sh", "response": {"log": "{stdout}"}, "next": {"type": "backup.done"}}}
A command starting with a .py or .pyz file runs with the Python of the connector.
They are read, by priority, from --handler (one command for every event), --handler-file, or the
"handlers" section of the configuration.
"""

import argparse
import asyncio
import collections
import fnmatch
import json
import re
import shlex
import os
import platform
import random
import subprocess
import sys
import typing
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
# Kept by Tower for each delivery
MAX_OUTPUT_BYTES = 65535
# Shown in the console only : Tower and the handlers receive the whole payload
MAX_SHOWN_PAYLOAD = 100
PYTHON_SCRIPTS = (".py", ".pyz")
# Answers waiting for the connection to come back : the oldest are dropped beyond
MAX_PENDING_ANSWERS = 1000


class InjectedOption(Exception):
    """A placeholder filled an argument with a value starting with a dash."""


class Fatal(Exception):
    """Tower refused this connector : retrying would not help."""


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog="tower-connector", description="Connects this device to Tower and runs a handler for each event.")
    parser.add_argument("config", nargs="?", help="configuration file, unless it is embedded in this connector")
    handlers = parser.add_mutually_exclusive_group()
    handlers.add_argument("--handler", help='command run for each event, e.g. --handler="python3 handler.py"')
    handlers.add_argument("--handler-file", metavar="FILE", help='JSON file mapping event patterns to the commands to run, instead of the "handlers" of the configuration')
    parser.add_argument("--handler-timeout", type=float, default=60, metavar="SECONDS", help="stop a handler running longer (default: 60)")
    parser.add_argument("--max-jobs", type=int, default=4, metavar="COUNT", help="handlers running at the same time (default: 4)")
    parser.add_argument("--no-color", action="store_true", help="plain output, for system logs")
    arguments = parser.parse_args()

    if arguments.handler_timeout <= 0 or arguments.max_jobs < 1:
        parser.error("--handler-timeout and --max-jobs must be positive")
    if arguments.handler is not None and not split_command(arguments.handler):
        parser.error("--handler is empty")

    return arguments


def split_command(command: str) -> list[str]:
    # Run without shell : the payload can never become a command, and HandlerFile refuses it as an option
    parts = [os.path.expanduser(part) for part in shlex.split(command, posix=os.name != "nt")]
    if parts and parts[0].lower().endswith(PYTHON_SCRIPTS):
        parts.insert(0, sys.executable)
    return parts


def shorten(text: str) -> str:
    return text if len(text) <= MAX_SHOWN_PAYLOAD else text[:MAX_SHOWN_PAYLOAD - 1] + "…"


def read_config(path: str | None) -> dict:
    if path:
        return json.loads(Path(path).read_text())

    try:
        with zipfile.ZipFile(sys.argv[0]) as archive:
            return json.loads(archive.read(EMBEDDED_CONFIG))
    except (KeyError, zipfile.BadZipFile, FileNotFoundError):
        raise Fatal("no configuration: python3 tower-connector.pyz <config.json>")


class Console:
    """`->` in blue for what Tower sends, `<-` in orange for what goes back, a blank line after each answer."""

    BLUE = "\033[34m"
    ORANGE = "\033[38;5;208m"
    BOLD = "\033[1m"
    DIM = "\033[2m"
    RESET = "\033[0m"

    def __init__(self, color: bool) -> None:
        self.color = color
        if color and os.name == "nt":
            os.system("")  # Turns on the ANSI sequences of the Windows console

    def paint(self, text: str, *styles: str) -> str:
        return f"{''.join(styles)}{text}{self.RESET}" if self.color else text

    def incoming(self, text: str, detail: str = "") -> None:
        arrow = self.paint("->", self.BLUE, self.BOLD)
        detail = shorten(detail)
        print(f"{arrow} {text}" + (f" {self.paint(detail, self.DIM)}" if detail else ""), flush=True)

    def outgoing(self, text: str, exit_code: int | None = None, output: str = "") -> None:
        arrow = self.paint("<-", self.ORANGE, self.BOLD)
        suffix = "" if exit_code is None else f" {self.paint(f'exit {exit_code}', self.BOLD)}"
        details = "".join(f"\n   {self.paint(line, self.DIM)}" for line in output.strip("\n").splitlines())
        print(f"{arrow} {text}{suffix}{details}\n", flush=True)

    def event(self, message: dict) -> str:
        return f"{self.paint(str(message.get('event')), self.BOLD)} {self.paint('#' + str(message.get('delivery')), self.DIM)}"


class Outbox:
    """Answers for Tower, kept while the connection is down and sent once it is back."""

    def __init__(self) -> None:
        self.socket = None
        self.pending: collections.deque[dict] = collections.deque(maxlen=MAX_PENDING_ANSWERS)
        self.lock = asyncio.Lock()

    async def attach(self, socket) -> None:
        self.socket = socket
        await self.flush()

    def detach(self) -> None:
        self.socket = None

    async def send(self, message: dict) -> None:
        self.pending.append(message)
        await self.flush()

    async def flush(self) -> None:
        async with self.lock:
            while self.pending and self.socket is not None:
                try:
                    await self.socket.send(json.dumps(self.pending[0]))
                except (OSError, websockets.WebSocketException):
                    self.socket = None
                    return
                self.pending.popleft()


PLACEHOLDER = re.compile(r"\{([A-Za-z0-9_.]+)\}")
# "response" missing : the outputs of the commands answer, as before the key existed
NO_RESPONSE = object()
HANDLER_KEYS = {"run", "response", "next"}


class Chain(typing.NamedTuple):
    commands: list[list[str]]
    response: object = NO_RESPONSE
    next: list = []


class SingleCommand:
    """--handler : the same command for every event."""

    def __init__(self, command: list[str]) -> None:
        self.command = command

    def chains_for(self, message: dict) -> list[Chain]:
        return [Chain([self.command])]


class HandlerFile:
    """--handler-file, or the "handlers" of the configuration : the commands of every pattern matching
    the event, in the order of the file. Each pattern is its own chain : a failing command stops its
    list, not the other patterns.

    A file is read again when it changes ; while it is invalid, the last valid version is kept. The
    configuration embedded in a connector cannot change.
    """

    def __init__(self, path: str | None, section: str | None = None, mapping: dict | None = None) -> None:
        self.path = Path(path).expanduser() if path else None
        self.section = section
        self.modified: float | None = None
        self.rules: list[tuple[str, Chain]] = []
        if mapping is not None:
            self.rules = self.parse(mapping)

    @property
    def name(self) -> str:
        return f"{self.path}" + (f" ({self.section})" if self.section else "")

    def load(self) -> None:
        content = json.loads(self.path.read_text())
        if self.section:
            content = content.get(self.section) if isinstance(content, dict) else None
        self.rules = self.parse(content)
        self.modified = self.path.stat().st_mtime

    @staticmethod
    def parse(content) -> list[tuple[str, Chain]]:
        if not isinstance(content, dict) or not content:
            raise ValueError("expected a JSON object of event patterns")

        return [(pattern, HandlerFile.parse_chain(pattern, value)) for pattern, value in content.items()]

    @staticmethod
    def parse_chain(pattern: str, value) -> Chain:
        """A command, a list of commands, or {"run": ..., "response": ..., "next": ...}."""
        handler = value if isinstance(value, dict) else {"run": value}
        unknown = set(handler) - HANDLER_KEYS
        if unknown:
            raise ValueError(f"{pattern!r} has unknown keys {sorted(unknown)}, expected {sorted(HANDLER_KEYS)}")

        run = handler.get("run")
        commands = [run] if isinstance(run, str) else run
        if not pattern or not isinstance(commands, list) or not commands or not all(isinstance(command, str) and split_command(command) for command in commands):
            raise ValueError(f"{pattern!r} must run a command or a non empty list of commands")

        following = handler.get("next", [])
        following = [following] if isinstance(following, dict) else following
        if not isinstance(following, list) or not all(isinstance(event, dict) and isinstance(event.get("type"), str) and set(event) <= {"type", "payload"} for event in following):
            raise ValueError(f"{pattern!r} : next must be an event {{\"type\": ..., \"payload\": ...}} or a list of them")

        return Chain([split_command(command) for command in commands], handler.get("response", NO_RESPONSE), following)

    def refresh(self) -> None:
        if self.path is None:
            return
        try:
            if self.path.stat().st_mtime == self.modified:
                return
            self.load()
            print(f"{self.name}: reloaded", file=sys.stderr, flush=True)
        except (OSError, ValueError) as error:
            print(f"{self.name}: {error}, keeping the previous version", file=sys.stderr, flush=True)

    def chains_for(self, message: dict) -> list[Chain]:
        self.refresh()
        event = str(message.get("event", ""))
        values = placeholder_values(message)

        # Replaced inside an argument, after splitting : a value never adds an argument
        def fill(command: list[str]) -> list[str]:
            filled = [fill_text(part, values) for part in command]
            for part, value in zip(command, filled):
                # git --upload-pack=, curl -o, rsync -e... : an option brought by the payload can run anything
                if value.startswith("-") and not part.startswith("-"):
                    raise InjectedOption(f"{part!r} would become the option {shorten(value)!r} of {command[0]}, not run")
            return filled

        return [chain._replace(commands=[fill(command) for command in chain.commands]) for pattern, chain in self.rules if fnmatch.fnmatchcase(event, pattern)]


def placeholder_values(message: dict) -> dict:
    """{event}, {id}, {delivery}, {workspace} and {payload.<field>}, nested fields with dots, as they came."""
    values = {key: message.get(key, "") for key in ("event", "id", "delivery", "workspace")}

    def flatten(prefix: str, value) -> None:
        values[prefix] = value
        if isinstance(value, dict):
            for key, child in value.items():
                flatten(f"{prefix}.{key}", child)

    flatten("payload", message.get("payload"))
    return values


def as_text(value) -> str:
    return value if isinstance(value, str) else json.dumps(value, ensure_ascii=False)


def fill_text(text: str, values: dict) -> str:
    """Unknown names stay as written."""
    return PLACEHOLDER.sub(lambda match: as_text(values[match.group(1)]) if match.group(1) in values else match.group(0), text)


def interpolate(value, values: dict):
    """Every string inside a JSON value. A string that is a single placeholder takes the value with its type."""
    if isinstance(value, str):
        alone = PLACEHOLDER.fullmatch(value)
        return values[alone.group(1)] if alone and alone.group(1) in values else fill_text(value, values)
    if isinstance(value, list):
        return [interpolate(item, values) for item in value]
    if isinstance(value, dict):
        return {key: interpolate(item, values) for key, item in value.items()}
    return value


class Result(typing.NamedTuple):
    status: str
    exit_code: int | None
    stdout: str
    stderr: str

    @property
    def output(self) -> str:
        """The output is the answer ; without one, the errors explain what happened."""
        return self.stdout if self.stdout.strip() else self.stderr


class Handler:
    """Runs the commands of each event, a few events at a time."""

    def __init__(self, source: SingleCommand | HandlerFile, timeout: float, max_jobs: int, outbox: Outbox, console: Console) -> None:
        self.source = source
        self.console = console
        self.timeout = timeout
        self.slots = asyncio.Semaphore(max_jobs)
        self.outbox = outbox

    async def run(self, message: dict) -> None:
        delivery = message.get("delivery")
        try:
            chains = self.source.chains_for(message)
        except InjectedOption as refusal:
            await self.outbox.send({"type": "response", "delivery": delivery, "status": "failed", "exit_code": None, "output": str(refusal)})
            self.console.outgoing(f"{self.console.event(message)} failed", None, str(refusal))
            return
        if not chains:
            await self.outbox.send({"type": "response", "delivery": delivery, "status": "received"})
            self.console.outgoing(f"{self.console.event(message)} received · no command")
            return

        async with self.slots:
            await self.outbox.send({"type": "response", "delivery": delivery, "status": "running"})
            status, exit_code, output, following = await self.execute_all(chains, message)

        chained = f" · next {', '.join(str(event.get('type')) for event in following)}" if following else ""
        self.console.outgoing(f"{self.console.event(message)} {status}{chained}", exit_code, output if status == "failed" else "")
        answer = {"type": "response", "delivery": delivery, "status": status, "exit_code": exit_code, "output": output}
        await self.outbox.send({**answer, "next": following} if following else answer)

    async def execute_all(self, chains: list[Chain], message: dict) -> tuple[str, int | None, str, list]:
        """Each chain in order, stopping at its first failure as `&&` would. The answer fails if a chain failed,
        with the exit code of the first failure, or of the last command otherwise. Each chain gives its
        response, and its next events when it succeeded."""
        outputs = []
        following = []
        failure = None
        status, exit_code = "succeeded", 0
        values = placeholder_values(message)

        for chain in chains:
            results = []
            for command in chain.commands:
                result = await self.execute(command, message)
                results.append(result)
                if result.status == "failed":
                    break

            status, exit_code = results[-1].status, results[-1].exit_code
            if status == "failed":
                failure = failure or (status, exit_code)

            chain_values = {
                **values,
                "stdout": "\n".join(result.stdout.rstrip("\n") for result in results if result.stdout.strip()),
                "stderr": "\n".join(result.stderr.rstrip("\n") for result in results if result.stderr.strip()),
                "exit_code": exit_code,
                "status": status,
            }
            if chain.response is NO_RESPONSE:
                outputs += [result.output.rstrip("\n") for result in results if result.output.strip()]
            else:
                outputs.append(as_text(interpolate(chain.response, chain_values)))
            if status == "succeeded":
                following += [interpolate(event, chain_values) for event in chain.next]

        status, exit_code = failure or (status, exit_code)
        return status, exit_code, "\n".join(outputs)[:MAX_OUTPUT_BYTES], following

    async def execute(self, command: list[str], message: dict) -> Result:
        environment = {
            **os.environ,
            "TOWER_EVENT": str(message.get("event", "")),
            "TOWER_EVENT_ID": str(message.get("id", "")),
            "TOWER_DELIVERY": str(message.get("delivery", "")),
            "TOWER_WORKSPACE": str(message.get("workspace", "")),
        }
        try:
            process = await asyncio.create_subprocess_exec(
                *command,
                stdin=asyncio.subprocess.PIPE,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                env=environment,
            )
        except OSError as error:
            return Result("failed", None, "", f"cannot start {command[0]}: {error}")

        try:
            stdout, stderr = await asyncio.wait_for(process.communicate(json.dumps(message).encode()), self.timeout)
        except asyncio.TimeoutError:
            process.kill()
            await process.wait()
            return Result("failed", None, "", f"timeout after {self.timeout:g}s")

        return Result(
            "succeeded" if process.returncode == 0 else "failed",
            process.returncode,
            stdout[:MAX_OUTPUT_BYTES].decode(errors="replace"),
            stderr[:MAX_OUTPUT_BYTES].decode(errors="replace"),
        )


class Connector:
    def __init__(self, source: SingleCommand | HandlerFile | None, timeout: float, max_jobs: int, console: Console) -> None:
        self.console = console
        self.outbox = Outbox()
        self.handler = Handler(source, timeout, max_jobs, self.outbox, console) if source else None
        # Running handlers outlive a connection : their answer waits in the outbox
        self.jobs: set[asyncio.Task] = set()

    def handle(self, message: dict) -> None:
        if "error" in message:
            raise Fatal(f"refused by Tower: {message['error']}")

        match message.get("type"):
            case "connection":
                self.console.incoming(f"Connected to Tower, connection {message['uuid']}")
                print(flush=True)
            case "disconnect":
                raise Fatal(f"disconnected by Tower: {message.get('reason', 'unknown')}")
            case "event":
                self.receive(message)
            case _:
                self.console.incoming(json.dumps(message, ensure_ascii=False))

    def receive(self, message: dict) -> None:
        self.console.incoming(self.console.event(message), json.dumps(message.get("payload"), ensure_ascii=False))
        job = self.handler.run(message) if self.handler else self.acknowledge(message)

        task = asyncio.create_task(job)
        self.jobs.add(task)
        task.add_done_callback(self.jobs.discard)

    async def acknowledge(self, message: dict) -> None:
        await self.outbox.send({"type": "response", "delivery": message.get("delivery"), "status": "received"})
        self.console.outgoing(f"{self.console.event(message)} received")


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


async def session(connector: Connector, config: dict, kind: str) -> None:
    headers = {
        "X-Tower-Client-Id": config["client_id"],
        "X-Tower-Secret": config["secret"],
        "X-Tower-Hostname": gethostname(),
        "X-Tower-Device-Type": kind,
    }
    user_agent = f"tower-connector ({platform.system()} {platform.release()}; {platform.machine()}; Python {platform.python_version()})"

    async with websockets.connect(config["tower_url"], additional_headers=headers, user_agent_header=user_agent) as socket:
        await connector.outbox.attach(socket)
        try:
            async for frame in socket:
                connector.handle(json.loads(frame))
        finally:
            connector.outbox.detach()


async def run(connector: Connector, config: dict) -> None:
    loop = asyncio.get_running_loop()
    kind = device_type()
    backoff = 1

    while True:
        started_at = loop.time()
        try:
            await session(connector, config, kind)
            reason = "connection closed"
        except (OSError, websockets.WebSocketException) as error:
            reason = str(error) or type(error).__name__

        if loop.time() - started_at >= STABLE_AFTER_SECONDS:
            backoff = 1

        delay = backoff + random.uniform(0, backoff / 2)
        print(f"{reason}, retrying in {delay:.0f}s", file=sys.stderr, flush=True)
        await asyncio.sleep(delay)
        backoff = min(backoff * 2, MAX_BACKOFF_SECONDS)


def handler_source(arguments: argparse.Namespace, config: dict) -> SingleCommand | HandlerFile | None:
    """--handler, then --handler-file, then the "handlers" of the configuration."""
    if arguments.handler:
        return SingleCommand(split_command(arguments.handler))

    try:
        if arguments.handler_file:
            source = HandlerFile(arguments.handler_file)
            source.load()
            return source
        if "handlers" not in config:
            return None
        if arguments.config:
            source = HandlerFile(arguments.config, section="handlers")
            source.load()
            return source
        return HandlerFile(None, mapping=config["handlers"])
    except ValueError as error:
        raise Fatal(f"handlers: {error}")


def main() -> int:
    arguments = parse_arguments()

    try:
        config = read_config(arguments.config)
        missing = [key for key in REQUIRED_KEYS if not config.get(key)]
        if missing:
            raise Fatal(f"missing in configuration: {', '.join(missing)}")

        handler = handler_source(arguments, config)
        console = Console(not arguments.no_color and sys.stdout.isatty() and "NO_COLOR" not in os.environ)
        asyncio.run(run(Connector(handler, arguments.handler_timeout, arguments.max_jobs, console), config))
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
