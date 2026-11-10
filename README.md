<p align="center">
  <img src="Frontend/public/favicon.svg" alt="Tower logo" width="96" height="96" />
</p>

<h1 align="center">Tower</h1>

<p align="center">
  <strong>A self-hosted control tower for your work and your machines.</strong><br />
  Tasks, Jira tickets and merge requests in one place, and every device you own reacting to them.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="MIT License" /></a>
  <img src="https://img.shields.io/badge/self--hosted-Docker-2190FF.svg" alt="Self-hosted with Docker" />
  <img src="https://img.shields.io/badge/AI--coded-Claude%20Code-D97757.svg" alt="AI-coded with Claude Code" />
</p>

---

## Why Tower

Your work is scattered: tasks in one tool, tickets in Jira, merge requests in Bitbucket, and a laptop, a desktop and a
server that each need to do something when one of those changes.

Tower brings them together. It follows your tools, keeps a timeline of everything that happens, and sends **events** to
the devices you connect, which run whatever you decided they should run: a notification, a deploy script, or a Claude
Code session opened on the ticket you are looking at.

Everything runs on your own server. Your devices open the connection to Tower themselves: no port to open, it works
behind any NAT.

## Features

### Tasks and sprints
- A board with five columns and a backlog, sprints you open and close yourself, drag and drop everywhere.
- Every change is kept in the task's own history, with who made it.

### Jira
- Your Jira board with **its own columns**, in its order, even the empty ones.
- **Who does what**: every ticket shows its assignee's avatar, or keep only yours with *My issues only*.
- Drag a ticket to another column to run the matching Jira transition. When several statuses fit, Tower asks which one.
- Filter by sprint, copy a key, open a ticket in Jira, or turn it into a Tower task.
- **Discuss**: one click sends the ticket to your devices, and the basic handler opens a Claude Code session about it,
  resumed the next time you click.

### Merge requests
- Your open Bitbucket merge requests, one tab per repository.
- A **tree graph** of stacked merge requests, with pipeline status, approvals and comments at a glance.

### Devices and events
- A tiny Python connector, a single file with no installation, connects each device to a workspace.
- Each device chooses which events it listens to (`task.*`, `pr.pipeline.failed`, `jira.issue.status_changed`…) and
  what to run for each of them.
- Every delivery is tracked: which devices received an event, whether their handler succeeded, and what it answered.
- Trigger events by hand, or save them as one-click **quick actions**.

### Precise integration events
Tower compares your tools with what it last saw every five minutes, and turns each difference into an event your
devices can subscribe to:

| Merge requests | Jira |
|---|---|
| `pr.opened`, `pr.merged`, `pr.declined` | `jira.issue.assigned`, `jira.issue.done`, `jira.issue.unassigned` |
| `pr.pipeline.failed`, `pr.pipeline.succeeded` | `jira.issue.status_changed` |
| `pr.review.approved`, `pr.review.changes_requested` | `jira.issue.sprint_changed`, `jira.issue.priority_changed` |
| `pr.commented`, `pr.pushed`, `pr.ready`, `pr.retargeted` | `jira.issue.updated` |

Each event carries the before and after values, so a handler can act without calling anything back.

### Workspaces
- Separate *work* from *home*: each workspace has its own tasks, integrations, devices and history.
- Pick the sections a workspace uses (tasks, Jira, merge requests, events) and give it a color.
- Share a workspace with other accounts; every member has the same rights.

### And also
- A full **history** of what happened, with the device deliveries of each event.
- Built-in **documentation**, light and dark themes, English and French.

## How it works

```
   Browser ──► Tower (PHP, MySQL) ◄── polls ── Jira Cloud, Bitbucket Cloud
                    │
                    │ WebSocket, opened by each device
                    ▼
        Connector on your laptop, desktop, server…
                    │
                    ▼
        Your handlers: scripts, notifications, Claude Code…
```

When an event reaches a device, the connector runs the matching command **without a shell**, with the event as JSON on
its standard input. Tower can only trigger what a device agreed to run: it never sends a command to execute.

## Getting started

Tower runs with Docker. You need Docker with Compose, and `make`.

```sh
git clone https://github.com/yonis-savary/tower.git
cd tower
cp .env.example .env
```

Edit `.env`: give a name to `COMPOSE_PROJECT_NAME`, set the database passwords and a long random `WEBSOCKET_SECRET`, and
set `UID`/`GID` to your own (`id -u`, `id -g`). Then build, generate the application key and start:

```sh
make build
docker compose -f docker-compose.yml run --rm php-fpm php do key:generate   # copy the printed key into APP_KEY
make up
docker compose -f docker-compose.yml run --rm php-fpm php do migrate:migrate
```

Open `http://localhost:8081` and create your account: your first workspace is created with it. Devices connect to the
WebSocket server on port `8088`.

In production, put both behind a TLS reverse proxy and list your public address in `WEBSOCKET_ALLOWED_ORIGINS`. Later
updates are a single `make deploy`.

## Connecting a device

1. In Tower, open **Devices**, then *Connect a device*.
2. Download the connector, and the basic handler if you want Claude Code discussions.
3. Create a configuration: it gives the file that identifies the device.
4. Run it:

```sh
python3 tower-connector.pyz config.json --handler tower-handler.pyz
```

Python 3.10 or later is all it needs. To start it with your session and restart it after a crash, see
*Documentation → Linux service* in the app.

Write your own handlers by mapping event patterns to commands in the configuration:

```json
"handlers": {
  "pr.pipeline.failed": "notify-send 'Pipeline failed' '{payload.title}'",
  "deploy.*": ["git -C ~/projects/api pull", "make -C ~/projects/api deploy"]
}
```

## Development

```sh
make dev   # the stack with your code mounted, plus the Vite dev server
docker compose exec php-fpm vendor/bin/phpunit
make sdk   # regenerate the OpenAPI document and the frontend client
```

The backend is built on [Cube](https://github.com/yonis-savary/cube), a typed PHP 8 framework. The architecture, the
decisions behind it and what is planned are described in [ARCHITECTURE.md](ARCHITECTURE.md) (in French).

| Part | Stack |
|---|---|
| Backend | PHP 8.5, Cube, MySQL |
| Frontend | Vue 3, PrimeVue, a client generated from the OpenAPI document |
| Connector | Python, shipped as a single zipapp |
| Real time | WebSocket |

## Roadmap

- GitLab and GitHub merge requests
- Jobs sent to one chosen device
- An AI agent that keeps tasks up to date from the history
- Notifications

## License

[MIT](LICENSE) © Yonis Savary
