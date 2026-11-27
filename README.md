<p align="center">
  <img src="Frontend/public/favicon.svg" alt="Tower logo" width="96" height="96" />
</p>

<h1 align="center">Tower</h1>

<p align="center">
  <strong>A self-hosted device and automation mediator.</strong><br />
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

Everything below is scoped to a **workspace**: separate *work* from *home*, each with its own data, devices and members.

- **Tasks** and **sprints**, on a board and a backlog, written in bulk from free **notes** (one line, one task), and a
  **timeline** of what was worked on over a period
- Your **Jira** boards, as profiles narrowed by an optional JQL filter, with **transitions by drag and drop**
- Your **Bitbucket** merge requests, as a **tree of stacked branches**
- **Documentation**: Markdown pages with **Mermaid diagrams**, images and their past versions
- A shared **agenda**, with recurring items and participants
- **Events** launched by hand or as **quick actions**, and sent to your **devices**
- **Automation**: events **scheduled** at intervals or at a given date, and **push notifications** on your phone or
  computer
- **Webhooks** for external services
- **AI agents** connected through **MCP**: Claude Code, Claude Desktop or any MCP client reads and acts on a workspace
- A **history** of everything, with what each device answered, and a **search** across the whole workspace
- Members, sections and a color per workspace
- Light and dark themes, English and French

## Connection

A device joins a workspace with the **connector**: a single Python file (3.10 or later, nothing to install) that opens a
WebSocket to Tower, so no port has to be opened on the device. Its configuration, created in Tower under **Devices**,
says which events it listens to and which command to run for each:

```json
"handlers": {
  "pr.pipeline.failed": "notify-send 'Pipeline failed' '{payload.title}'",
  "deploy.*": ["git -C ~/projects/api pull", "make -C ~/projects/api deploy"]
}
```

```sh
python3 tower-connector.pyz config.json --handler tower-handler.pyz
```

Commands run without a shell, with the event as JSON on their standard input, and their result comes back to the
history. The handlers can be built in a form in Tower, which writes them into the downloaded configuration, also
available as a single-use `curl` link. Tower only triggers what a device agreed to run: it never sends a command to
execute. The optional **basic handler** opens a Claude Code session on a Jira ticket. To run the connector as a service,
see *Help / API → Linux service* in the app.

## AI agents (MCP)

Tower is also an **MCP server**, at `/mcp`. An agent connects with a **personal token**, created in **Settings → AI
agents** and bound to one workspace: it reads the tasks, sprints, Jira issues, merge requests, documentation and
history, and with a write token creates or moves tasks, changes the status of an issue or sends events to your devices.

Each workspace chooses what agents may do in it (blocked, read, or read and write), whatever their token, and every
call shows in the history under the name of its token. Secrets, access management and deletions are never exposed to
agents. The setup commands for Claude Code and Claude Desktop are in *Help / API → AI agents (MCP)* in the app.

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
make exec php do key:generate   # copy the printed key into APP_KEY
make up
make exec php do migrate:migrate
```

`make exec <command>` runs a command in the PHP container, a one-off one while the stack is stopped. Put a command with
options in quotes, or `make` reads them as its own: `make exec "php do migrate:rollback --steps=2"`.

Create your account, with its first workspace, and log in at `http://localhost:8081` with the printed password:

```sh
make exec php do app:create-user you@example.com
```

Registration from the login page is closed unless `REGISTRATION_OPEN=true` in `.env`: on a public instance, anyone
could then create an account. Otherwise every account is created with this command.

Devices connect to the WebSocket server on port `8088`. Both ports are set in `.env` (`TOWER_HTTP_PORT`, `TOWER_WEBSOCKET_PORT`).

Push notifications are optional: generate their keys once, copy them into `.env` (`VAPID_PUBLIC_KEY`,
`VAPID_PRIVATE_KEY`, plus a `VAPID_SUBJECT` such as `mailto:you@example.com`), then restart with `make down up`:

```sh
make exec php do app:push-keys
```

In production:

- set `env=prod` in `.env`: in `debug`, an error answers with its stack trace;
- put both ports behind a TLS reverse proxy, on one domain if you like (the websocket paths are `/devices` and
  `/workspaces/<id>/devices`), which sends `X-Forwarded-Proto: https` (the session cookie is only marked `Secure` with
  it) and `Strict-Transport-Security`, and keep ports `8081` and `8088` out of public reach (firewall), since Docker
  publishes them on every interface;
- give the proxy's address to `TOWER_WEBSOCKET_URL` (`wss://tower.example.com`) and list your public address in
  `WEBSOCKET_ALLOWED_ORIGINS`.

Later updates are a single `make deploy`.

## Development

The stack, the development commands and the architecture are described in [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

## License

[MIT](LICENSE) © Yonis Savary
