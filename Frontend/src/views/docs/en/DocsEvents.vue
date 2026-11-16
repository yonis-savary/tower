<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()

const taskExample = `
{
  "task": 42,
  "title": "Prepare the demo",
  "status": "in_review",
  "sprint": 7,
  "from": "in_progress",
  "to": "in_review"
}
`

const jiraExample = `
{
  "source": "jira",
  "key": "PLAT-12",
  "summary": "Fix the login",
  "url": "https://team.atlassian.net/browse/PLAT-12",
  "from": "In Progress",
  "to": "In Review",
  "from_category": "in_progress",
  "to_category": "in_progress"
}
`

const discussExample = `
{
  "source": "jira",
  "key": "PLAT-12",
  "url": "https://team.atlassian.net/browse/PLAT-12",
  "summary": "Fix the login",
  "project": "Platform",
  "issue_type": "Bug",
  "priority": "High",
  "status": "In Progress",
  "sprint": "Sprint 4",
  "assignee": "Alice",
  "reporter": "Bob",
  "labels": ["auth"],
  "description": "h2. Steps\\n...",
  "comments": [{ "author": "Bob", "created": "2026-11-10T10:00:00.000+0100", "body": "Seen in production" }]
}
`
</script>

<template>
  <h2>Events</h2>
  <p class="lead">What Tower sends to the connected devices, when, and with which data.</p>

  <h3>Receiving an event</h3>
  <p>
    A device receives the events its configuration <strong>listens</strong> to, and the connector runs the commands of its
    handlers for each of them, with the whole message as JSON on their standard input: <code>type</code>,
    <code>id</code> (history line), <code>delivery</code>, <code>workspace</code>, <code>event</code> and
    <code>payload</code>. Everything is detailed in
    <a href="#" @click.prevent="$emit('open', 'connector')">Connector</a>; this page describes the <code>payload</code> of
    each event.
  </p>

  <h3>Tasks</h3>
  <p>
    Always present: <code>task</code> (id), <code>title</code>, <code>status</code> and <code>sprint</code> (id,
    <code>null</code> in the backlog). The statuses are <code>todo</code>, <code>in_progress</code>, <code>in_review</code>,
    <code>finalizing</code>, <code>done</code> and <code>cancelled</code>.
  </p>
  <table>
    <thead><tr><th>Event</th><th>When</th><th>Extra fields</th></tr></thead>
    <tbody>
      <tr><td><code>task.created</code></td><td>a task is created</td><td></td></tr>
      <tr><td><code>task.updated</code></td><td>its title or description changes</td><td><code>previous_title</code></td></tr>
      <tr><td><code>task.status_changed</code></td><td>its status changes</td><td><code>from</code>, <code>to</code></td></tr>
      <tr><td><code>task.sprint_changed</code></td><td>it moves to another sprint</td><td><code>from</code>, <code>to</code>: sprint names, <code>null</code> for the backlog</td></tr>
    </tbody>
  </table>
  <CodeBlock :code="taskExample" language="json" filename="task.status_changed" />

  <h3>Sprints</h3>
  <p><code>sprint</code> (id) and <code>name</code>.</p>
  <table>
    <thead><tr><th>Event</th><th>When</th></tr></thead>
    <tbody>
      <tr><td><code>sprint.created</code></td><td>a sprint is planned</td></tr>
      <tr><td><code>sprint.opened</code></td><td>it becomes active, alongside the other active sprints</td></tr>
      <tr><td><code>sprint.closed</code></td><td>it is closed</td></tr>
    </tbody>
  </table>

  <h3>Merge requests</h3>
  <p>
    Always present: <code>source</code> (<code>bitbucket</code>), <code>id</code>, <code>repository</code>,
    <code>title</code> and <code>url</code>. These events come from the synchronization, every 5 minutes.
  </p>
  <table>
    <thead><tr><th>Event</th><th>When</th><th>Extra fields</th></tr></thead>
    <tbody>
      <tr><td><code>pr.opened</code></td><td>a merge request opens</td><td></td></tr>
      <tr><td><code>pr.merged</code></td><td>it is merged</td><td></td></tr>
      <tr><td><code>pr.declined</code></td><td>it closes otherwise: declined, superseded, not found</td><td><code>state</code>, <code>null</code> when not found</td></tr>
      <tr><td><code>pr.pipeline.failed</code></td><td>its pipeline fails</td><td><code>from</code>, <code>to</code></td></tr>
      <tr><td><code>pr.pipeline.succeeded</code></td><td>its pipeline succeeds</td><td><code>from</code>, <code>to</code></td></tr>
      <tr><td><code>pr.review.approved</code></td><td>a reviewer approves</td><td><code>reviewer</code></td></tr>
      <tr><td><code>pr.review.changes_requested</code></td><td>a reviewer requests changes</td><td><code>reviewer</code></td></tr>
      <tr><td><code>pr.commented</code></td><td>new comments arrive</td><td><code>count</code> (the new ones), <code>from</code>, <code>to</code></td></tr>
      <tr><td><code>pr.pushed</code></td><td>a new commit lands on the source branch</td><td><code>branch</code>, <code>from</code>, <code>to</code> (commits)</td></tr>
      <tr><td><code>pr.ready</code></td><td>it leaves the draft state</td><td></td></tr>
      <tr><td><code>pr.retargeted</code></td><td>its target branch changes</td><td><code>from</code>, <code>to</code></td></tr>
    </tbody>
  </table>

  <h3>Jira issues</h3>
  <p>
    Always present: <code>source</code> (<code>jira</code>), <code>key</code>, <code>summary</code> and <code>url</code>.
    Only <strong>your</strong> issues, the ones assigned to the account of the token, send events: the other issues of the
    board are shown without sending anything. Synchronization every 5 minutes.
  </p>
  <table>
    <thead><tr><th>Event</th><th>When</th><th>Extra fields</th></tr></thead>
    <tbody>
      <tr><td><code>jira.issue.assigned</code></td><td>an issue of the board becomes yours</td><td></td></tr>
      <tr><td><code>jira.issue.done</code></td><td>one of yours is done: "done" category, or last column of the board</td><td><code>status</code></td></tr>
      <tr><td><code>jira.issue.unassigned</code></td><td>one of yours is not anymore: reassigned, out of the board, deleted</td><td><code>status</code>, <code>assignee</code></td></tr>
      <tr><td><code>jira.issue.status_changed</code></td><td>its status changes, in Jira or by a drag and drop in Tower</td><td><code>from</code>, <code>to</code>, <code>from_category</code>, <code>to_category</code></td></tr>
      <tr><td><code>jira.issue.sprint_changed</code></td><td>it moves to another sprint</td><td><code>from</code>, <code>to</code>: names, <code>null</code> without sprint</td></tr>
      <tr><td><code>jira.issue.priority_changed</code></td><td>its priority changes</td><td><code>from</code>, <code>to</code></td></tr>
      <tr><td><code>jira.issue.updated</code></td><td>its summary or type changes</td><td><code>fields</code>, <code>previous_summary</code></td></tr>
    </tbody>
  </table>
  <p>The categories are <code>todo</code>, <code>in_progress</code> or <code>done</code>.</p>
  <CodeBlock :code="jiraExample" language="json" filename="jira.issue.status_changed" />

  <h3>AI discussion</h3>
  <p>
    <code>ai.discuss</code> is sent when you click <em>Discuss</em>, on a Jira issue or on a task linked to an issue. The
    issue is read from Jira at that moment; the description and the last 20 comments are in Jira wiki text. The basic
    handler opens a Claude Code session with this content.
  </p>
  <CodeBlock :code="discussExample" language="json" filename="ai.discuss" />

  <h3>Events triggered by hand</h3>
  <p>
    From the Events screen, or through a quick action: the type and the payload are the ones you entered. In the History,
    they show as <em>Event launched</em>.
  </p>

  <h3>Scheduled events</h3>
  <p>
    From the Automation screen, Schedules tab: the same type and payload, launched every minute, every N minutes, every hour, day, week
    or month, or once at a given date. Times are the server ones, to the minute. A run missed while the server was down is
    not caught up: a one-time schedule whose date passed is simply disabled. In the History, they show as
    <em>Event launched</em>, by the schedule.
  </p>

  <h3>What is not sent</h3>
  <p>
    Device connections and disconnections, and the creation or revocation of configurations, are only written in the
    History.
  </p>
</template>
