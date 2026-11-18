<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'
import { claudeCodeCommand, claudeDesktopConfig, mcpJsonConfig, mcpUrl } from '../../../utils/mcpSetup'
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()

const url = mcpUrl()
const token = 'tower_…'
</script>

<template>
  <h2>AI agents (MCP)</h2>
  <p class="lead">Connect Claude Code, Claude Desktop or any MCP client to a Tower workspace.</p>

  <h3>How it works</h3>
  <ul>
    <li>
      Tower is an <strong>MCP</strong> (Model Context Protocol) server at <code>{{ url }}</code>. An agent reads the tasks,
      sprints, Jira issues, merge requests and history, and can act: create or move a task, change the status of an issue,
      send an event to your devices.
    </li>
    <li>
      It connects with a <strong>personal token</strong> bound to one workspace: it acts on your behalf, on that workspace
      only. Its actions show in the History with the name of the token.
    </li>
    <li>Nothing to install on the server: an address and a token are enough.</li>
  </ul>

  <h3>1. Open the workspace to agents</h3>
  <p>
    In the workspace settings, <em>Features</em> tab, <em>AI agent access</em> sets what an agent may do in the workspace,
    whatever its token:
  </p>
  <table>
    <thead><tr><th>Level</th><th>Effect</th></tr></thead>
    <tbody>
      <tr><td>Blocked</td><td>no connection, no token can be created</td></tr>
      <tr><td>Read</td><td>the read tools only, even for a write token (default)</td></tr>
      <tr><td>Read and write</td><td>the write tools for the tokens allowed to use them</td></tr>
    </tbody>
  </table>

  <h3>2. Create a token</h3>
  <p>
    In <strong>Settings → AI agents</strong>, on the workspace you want: a name (the device or the agent), rights (read
    only, or read and write) and an expiration. The token is shown <strong>once</strong>, with the commands below already
    filled in. It is revoked in one click, and stops working if you leave the workspace.
  </p>
  <div class="note">
    A token gives access to the workspace on your behalf: keep it like a password, and prefer read only when the agent
    does not need to act. Two workspaces means two tokens and two MCP servers on the agent's side.
  </div>

  <h3>3. Connect the agent</h3>
  <p><strong>Claude Code</strong>, one command:</p>
  <CodeBlock :code="claudeCodeCommand(url, token)" language="bash" filename="terminal" />
  <p>
    Add <code>--scope user</code> to have it in every project, or <code>--scope project</code> to write it in the project's
    <code>.mcp.json</code> (without leaving the token in it if the file is versioned). <code>/mcp</code> in Claude Code shows
    whether the connection works.
  </p>

  <p><strong>Claude Desktop</strong> only launches local programs from its configuration: <code>mcp-remote</code> (requires Node.js) relays. In <em>Settings → Developer → Edit Config</em>:</p>
  <CodeBlock :code="claudeDesktopConfig(url, token)" language="json" filename="claude_desktop_config.json" />
  <p>Then restart Claude Desktop.</p>

  <p><strong>Other clients</strong> (Cursor, VS Code, …): most read this format, with the HTTP transport and a header:</p>
  <CodeBlock :code="mcpJsonConfig(url, token)" language="json" filename=".mcp.json" />

  <h3>Tools</h3>
  <p>A write tool starts its description with <code>[write]</code>: clients usually ask for confirmation before calling it.</p>
  <table>
    <thead><tr><th>Tool</th><th>Effect</th></tr></thead>
    <tbody>
      <tr><td><code>list_tasks</code>, <code>list_sprints</code>, <code>list_task_history</code></td><td>the tasks, the sprints, the history of a task</td></tr>
      <tr><td><code>create_task</code>, <code>update_task</code>, <code>move_task</code></td><td>write: create, edit, change status or sprint</td></tr>
      <tr><td><code>list_jira_issues</code>, <code>list_jira_transitions</code></td><td>the tracked Jira issues (filterable: mine, by status category), the transitions allowed for an issue</td></tr>
      <tr><td><code>get_jira_issue</code>, <code>get_jira_attachment</code></td><td>an issue read from Jira by its key, on the board or not (description, comments, attachments), and an attachment of 5 MiB at most: an image comes as an image</td></tr>
      <tr><td><code>transition_jira_issue</code>, <code>create_task_from_jira_issue</code></td><td>write: change the status in Jira, create a linked task</td></tr>
      <tr><td><code>list_pull_requests</code></td><td>the open and recently closed merge requests</td></tr>
      <tr><td><code>list_history</code>, <code>list_event_deliveries</code></td><td>the workspace history, the devices an event reached</td></tr>
      <tr><td><code>suggest_event_types</code>, <code>list_quick_actions</code>, <code>list_scheduled_events</code></td><td>the known event types, the quick actions, the schedules</td></tr>
      <tr><td><code>trigger_event</code>, <code>trigger_quick_action</code></td><td>write: send an event to the devices</td></tr>
    </tbody>
  </table>
  <p>
    Nothing touches secrets or access (configurations, webhooks, integrations, members) and nothing deletes. See
    <a href="#" @click.prevent="$emit('open', 'events')">Events</a> for what the devices receive.
  </p>

  <h3>When it fails</h3>
  <table>
    <thead><tr><th>Answer</th><th>Cause</th></tr></thead>
    <tbody>
      <tr><td><code>401</code></td><td>missing, unknown, revoked or expired token, disabled account, or you are no longer a member of the workspace</td></tr>
      <tr><td><code>403</code></td><td>the workspace blocks AI agents</td></tr>
      <tr><td>tool missing from the list</td><td>a write tool with a read-only token, or a read-only workspace</td></tr>
    </tbody>
  </table>
</template>
