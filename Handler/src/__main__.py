"""Tower handler : python3 tower-handler.pyz [--cwd DIR] [--terminal "<command>"]

The basic handler of Tower, run by the connector with the event as JSON on its standard input :
    {"handlers": {"ai.discuss": "~/tower/tower-handler.pyz"}}

ai.discuss, sent by the Discuss button of a Jira issue, opens a terminal window running Claude Code
about this issue. The first time, a new session is created ; afterwards, the same session is resumed.
The issue, read from Jira when the button was pressed, is given to Claude as its system prompt.

Claude always starts in the same directory (--cwd, the home directory by default) : a session can
only be resumed where it was created. Claude looks for the repository of the Jira project itself.
"""

import argparse
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import uuid
from pathlib import Path

DISCUSS_EVENT = "ai.discuss"
ISSUE_KEY = re.compile(r"^[A-Z][A-Z0-9_]*-[0-9]+$")
# Linux refuses a single argument over 128 KiB : the system prompt is one
MAX_SYSTEM_PROMPT = 100_000
SESSION_NAMESPACE = uuid.UUID("6f1d3c2a-8b4e-4f5a-9c7d-2e1b0a9f8d6c")

# The arguments put before the command to run, by terminal, in order of preference
TERMINALS = [
    ("wezterm", ["start", "--"]),
    ("kitty", []),
    ("konsole", ["-e"]),
    ("gnome-terminal", ["--"]),
    ("alacritty", ["-e"]),
    ("foot", []),
    ("xfce4-terminal", ["-x"]),
    ("x-terminal-emulator", ["-e"]),
    ("xterm", ["-e"]),
]


class Refusal(Exception):
    pass


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog="tower-handler", description="Basic handler of Tower events.")
    parser.add_argument("--cwd", default=str(Path.home()), help="directory Claude starts in (default: the home directory)")
    parser.add_argument("--terminal", help='command opening a terminal, followed by the command to run, e.g. --terminal "kitty --single-instance"')
    subcommands = parser.add_subparsers(dest="action")
    # Run inside the new terminal window, not by the connector
    discussion = subcommands.add_parser("open-discussion")
    discussion.add_argument("workspace")
    discussion.add_argument("key")
    return parser.parse_args()


def session_id(workspace: str, key: str) -> str:
    return str(uuid.uuid5(SESSION_NAMESPACE, f"{workspace}:{key}"))


def session_exists(session: str) -> bool:
    config = Path(os.environ.get("CLAUDE_CONFIG_DIR", Path.home() / ".claude"))
    return any((config / "projects").glob(f"*/{session}.jsonl"))


def prompt_path(workspace: str, key: str) -> Path:
    return Path.home() / ".tower" / "discussions" / f"{workspace}-{key}.md"


def system_prompt(issue: dict) -> str:
    comments = list(issue.get("comments") or [])

    while True:
        prompt = render_prompt(issue, comments)
        if len(prompt) <= MAX_SYSTEM_PROMPT or not comments:
            return prompt[:MAX_SYSTEM_PROMPT]
        comments.pop(0)


def render_prompt(issue: dict, comments: list[dict]) -> str:
    details = [
        ("Lien", issue.get("url")),
        ("Résumé", issue.get("summary")),
        ("Type", issue.get("issue_type")),
        ("Priorité", issue.get("priority")),
        ("Statut", issue.get("status")),
        ("Sprint", issue.get("sprint")),
        ("Assigné à", issue.get("assignee")),
        ("Rapporteur", issue.get("reporter")),
        ("Libellés", ", ".join(issue.get("labels") or [])),
    ]
    lines = [
        f"# Ticket Jira {issue['key']}",
        "",
        "Cette session de Claude Code est une discussion avec l'utilisateur à propos du ticket Jira ci-dessous.",
        "Il a été relu dans Jira au lancement de la session : il a pu changer depuis le début de la conversation.",
        f"Projet Jira : {issue.get('project') or 'inconnu'}. Le dossier courant n'est pas forcément son dépôt :"
        " si tu as besoin du code, cherche le dépôt de ce projet sur cette machine.",
        "",
        *(f"- {label} : {value}" for label, value in details if value),
        "",
        "## Description",
        "",
        issue.get("description") or "(vide)",
    ]

    if comments:
        lines += ["", "## Commentaires, du plus ancien au plus récent"]
        for comment in comments:
            lines += ["", f"### {comment.get('author', '')}, {comment.get('created', '')}", "", comment.get("body", "")]

    return "\n".join(lines)


def terminal_command(override: str | None) -> list[str]:
    if override:
        return shlex.split(override)
    if os.environ.get("TERMINAL"):
        return [*shlex.split(os.environ["TERMINAL"]), "-e"]
    for name, arguments in TERMINALS:
        if shutil.which(name):
            return [name, *arguments]
    raise Refusal("no terminal found : give one with --terminal, or set $TERMINAL")


def open_window(command: list[str], terminal: str | None, cwd: str) -> str:
    # Detached, without the pipes of the connector : it waits for them to close before answering Tower
    detached = {"cwd": cwd, "stdin": subprocess.DEVNULL, "stdout": subprocess.DEVNULL, "stderr": subprocess.DEVNULL}

    if sys.platform == "win32":
        subprocess.Popen(command, creationflags=subprocess.CREATE_NEW_CONSOLE, **detached)
        return "a new console"

    if sys.platform == "darwin" and not terminal:
        script = shlex.join(["cd", cwd]) + " && " + shlex.join(command)
        escaped = script.replace("\\", "\\\\").replace('"', '\\"')
        subprocess.Popen(["osascript", "-e", f'tell application "Terminal" to do script "{escaped}"', "-e", 'tell application "Terminal" to activate'], start_new_session=True, **detached)
        return "Terminal"

    if not (terminal or os.environ.get("DISPLAY") or os.environ.get("WAYLAND_DISPLAY")):
        raise Refusal("no graphical session : the connector must run in the desktop session to open a window")

    window = terminal_command(terminal)
    subprocess.Popen([*window, *command], start_new_session=True, **detached)
    return window[0]


def discuss(message: dict, arguments: argparse.Namespace) -> str:
    issue = message.get("payload") or {}
    workspace = str(message.get("workspace", ""))
    key = str(issue.get("key", ""))

    if issue.get("source") != "jira":
        raise Refusal(f"{DISCUSS_EVENT} from {issue.get('source')!r} is not supported, only from jira")
    if not ISSUE_KEY.match(key) or not workspace.isdigit():
        raise Refusal(f"invalid issue key {key!r} or workspace {workspace!r}")
    if not shutil.which("claude"):
        raise Refusal("claude is not installed on this device, or not in the PATH of the connector")

    path = prompt_path(workspace, key)
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    # Created private : writing first and restricting after leaves a moment where others can read it
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    path.chmod(0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8") as prompt:
        prompt.write(system_prompt(issue))

    # The ticket travels through the file : only safe values are given to the terminal
    command = [sys.executable, os.path.abspath(sys.argv[0]), "--cwd", arguments.cwd, "open-discussion", workspace, key]
    where = open_window(command, arguments.terminal, arguments.cwd)

    session = session_id(workspace, key)
    state = "resumed" if session_exists(session) else "started"
    return f"Discussion about {key} {state} in {where} (Claude session {session}, in {arguments.cwd})"


def open_discussion(arguments: argparse.Namespace) -> int:
    session = session_id(arguments.workspace, arguments.key)
    claude = shutil.which("claude")
    if not claude:
        input("claude is not installed, or not in the PATH. Press Enter to close.")
        return 1

    prompt = prompt_path(arguments.workspace, arguments.key).read_text(encoding="utf-8")
    command = [claude, "--append-system-prompt", prompt]
    command += ["--resume", session] if session_exists(session) else ["--session-id", session]

    os.chdir(arguments.cwd)
    return subprocess.call(command)


def main() -> int:
    arguments = parse_arguments()
    if arguments.action == "open-discussion":
        return open_discussion(arguments)

    try:
        message = json.load(sys.stdin)
        if message.get("event") != DISCUSS_EVENT:
            print(f"{message.get('event')}: nothing to do")
            return 0

        print(discuss(message, arguments))
        return 0
    except (Refusal, OSError, ValueError) as error:
        print(f"tower-handler: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
