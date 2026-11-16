<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()

const run = `
python3 tower-connector.pyz config.json
python3 tower-connector.pyz config.json --handler-file ~/tower/handlers.json
python3 tower-connector.pyz config.json --handler "python3 ~/tower/my-handler.py"
`

const config = `
{
  "tower_url": "wss://tower.example.com/devices",
  "client_id": "…",
  "secret": "…",
  "handlers": {
    "task.status_changed": "notify-send Tower \\"{payload.title}: {payload.to}\\"",
    "pr.pipeline.failed": "notify-send \\"Pipeline failed\\" \\"{payload.title}\\"",
    "deploy.*": ["git -C ~/projects/api pull", "make -C ~/projects/api deploy"],
    "ai.discuss": "~/tower/tower-handler.pyz --cwd ~/projects"
  }
}
`

const envelope = `
{
  "type": "event",
  "id": 1842,
  "delivery": 3307,
  "workspace": 1,
  "event": "deploy.start",
  "payload": { "env": "prod", "services": ["api", "front"] }
}
`

const shell = `
"backup.run": "sh -c 'tar czf ~/backup.tgz ~/projects && rclone copy ~/backup.tgz remote:'"
`
</script>

<template>
  <h2>The connector</h2>
  <p class="lead">The program linking a device to Tower, and how to tell it what to run for each event.</p>

  <h3>What to know</h3>
  <ul>
    <li>A single file, <code>tower-connector.pyz</code>, needing Python 3.10 or later and nothing to install.</li>
    <li>
      It opens the connection (WebSocket) to Tower itself: no port has to be opened on the device. When it drops, it is
      opened again on its own, waiting longer each time, up to a minute.
    </li>
    <li>A device offline when an event happens never receives it: Tower keeps nothing waiting.</li>
    <li>
      When Tower refuses it (revoked configuration) or disconnects it from the Devices dialog, it stops with exit code 1
      instead of retrying.
    </li>
  </ul>

  <h3>Running it</h3>
  <p>
    The <strong>Devices</strong> dialog, <em>Connect a device</em> tab, creates a <strong>configuration</strong>. It downloads
    two ways: a <code>config.json</code> file to give the connector, or a <strong>preconfigured client</strong> embedding it,
    which runs without any argument.
  </p>
  <CodeBlock :code="run" language="bash" />
  <table>
    <thead><tr><th>Option</th><th>Effect</th></tr></thead>
    <tbody>
      <tr><td><code>--handler "&lt;command&gt;"</code></td><td>a single command, run for every event</td></tr>
      <tr><td><code>--handler-file &lt;file&gt;</code></td><td>handlers read from this JSON file instead of the configuration</td></tr>
      <tr><td><code>--handler-timeout &lt;s&gt;</code></td><td>stops a command running longer (60 by default)</td></tr>
      <tr><td><code>--max-jobs &lt;n&gt;</code></td><td>events handled at the same time (4 by default)</td></tr>
      <tr><td><code>--no-color</code></td><td>plain output, for system logs (also with <code>NO_COLOR</code>)</td></tr>
    </tbody>
  </table>
  <p>
    To start it with each session or at boot, see
    <a href="#" @click.prevent="$emit('open', 'linux-service')">Linux service</a> and
    <a href="#" @click.prevent="$emit('open', 'linux-server')">Headless server</a>.
  </p>

  <h3>Choosing the events received</h3>
  <p>
    A configuration only sends its devices the events it <strong>listens</strong> to: up to 20 patterns, set in the Devices
    dialog, <code>*</code> when created. <code>*</code> matches any sequence of characters, dots included:
    <code>task.*</code>, <code>pr.pipeline.*</code>, <code>jira.issue.status_changed</code>. An empty list receives nothing.
    This filter applies on Tower's side: an event not listened to does not even show in the connector's console.
  </p>

  <h3>Handlers</h3>
  <p>
    Handlers map an event pattern to a command, or to a list of commands. They go in the <code>handlers</code> section of
    <code>config.json</code>, or in a separate file given with <code>--handler-file</code>, which then holds the object of
    patterns directly:
  </p>
  <CodeBlock :code="config" language="json" filename="config.json" />
  <ul>
    <li>
      Patterns follow the same syntax as listening. An event runs the commands of <strong>every</strong> pattern matching
      it, in the order of the file.
    </li>
    <li>A list stops at its first failing command, like <code>&amp;&amp;</code>. A failing pattern does not stop the next ones.</li>
    <li>
      The file is read again as soon as it changes, without restarting the connector; while it is invalid, the previous
      version is kept. The embedded configuration of a preconfigured client cannot change: use
      <code>--handler-file</code> to complete it.
    </li>
    <li>
      A command whose first word is a <code>.py</code> or <code>.pyz</code> file runs with the connector's Python.
      <code>~</code> is replaced by your home directory.
    </li>
    <li>Without any handler, the connector only acknowledges the events.</li>
  </ul>

  <h3>Variables in commands</h3>
  <table>
    <thead><tr><th>Variable</th><th>Value</th></tr></thead>
    <tbody>
      <tr><td><code>{event}</code></td><td>the type of the event</td></tr>
      <tr><td><code>{id}</code></td><td>the history line of the event</td></tr>
      <tr><td><code>{delivery}</code></td><td>the delivery to this device</td></tr>
      <tr><td><code>{workspace}</code></td><td>the id of the workspace</td></tr>
      <tr><td><code>{payload.field}</code></td><td>a field of the payload, nested fields with dots (<code>{payload.author.name}</code>); an object or a list is written as JSON</td></tr>
    </tbody>
  </table>
  <p>
    An unknown variable stays as written. A value replaces text inside an argument, after the command is split: it never
    adds an argument, even when it contains spaces.
  </p>

  <h3>No shell</h3>
  <p>
    Commands run <strong>directly</strong>, without a shell: the payload can never become a command or an option. In
    return, <code>|</code>, <code>&amp;&amp;</code>, <code>&gt;</code>, <code>$VAR</code> or <code>*</code> mean nothing
    special. When you need them, call a shell yourself, without putting any <code>{payload…}</code> variable in it, or
    write a script:
  </p>
  <CodeBlock :code="shell" language="json" />

  <h3>What the command receives</h3>
  <p>The whole message, as JSON, on its <strong>standard input</strong>:</p>
  <CodeBlock :code="envelope" language="json" />
  <p>
    And in its environment: <code>TOWER_EVENT</code>, <code>TOWER_EVENT_ID</code>, <code>TOWER_DELIVERY</code> and
    <code>TOWER_WORKSPACE</code>. The content of each event is described in
    <a href="#" @click.prevent="$emit('open', 'events')">Events</a>.
  </p>

  <h3>What goes back to Tower</h3>
  <p>
    In the History, under the event, each device shows the status of its delivery: <em>Received</em> without a command,
    <em>Running</em>, then <em>Succeeded</em> or <em>Failed</em>, with the exit code and the output of the command (its
    standard output, or its errors when it is empty, 64 KiB at most). A command running past the timeout is stopped and
    counts as a failure. Answers sent while the connection is down leave as soon as it is back.
  </p>
</template>
