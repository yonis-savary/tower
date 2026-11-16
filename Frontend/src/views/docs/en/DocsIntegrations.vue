<script setup lang="ts">
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()
</script>

<template>
  <h2>Integrations</h2>
  <p class="lead">Plugging Jira Cloud and Bitbucket Cloud into a workspace, and what Tower reads from them.</p>

  <h3>What to know</h3>
  <ul>
    <li>
      Each integration is set in <strong>Settings</strong>, Jira or Bitbucket tab, shown when the matching section is
      checked in the workspace.
    </li>
    <li>
      Tower reads your tools <strong>every 5 minutes</strong>, or right away with the <em>Synchronize</em> button, and turns each
      difference into an event (see <a href="#" @click.prevent="$emit('open', 'events')">Events</a>).
    </li>
    <li>
      Tower acts with <strong>your</strong> account: it sees what the token sees, and a Jira transition made from Tower is
      made in your name.
    </li>
    <li>
      The token is encrypted in the database and never given back by the API. To replace it, type a new one; leave the
      field empty to keep the current one.
    </li>
    <li>
      When a sync fails (expired token, missing rights…), the error shows in the tab of the integration until the next
      successful sync.
    </li>
  </ul>

  <h3>Creating an Atlassian API token</h3>
  <p>
    Jira and Bitbucket use an API token of your Atlassian account, with that account's e-mail. It is created on
    <code>id.atlassian.com</code>, <em>Security → Create and manage API tokens</em>. A token has an expiry date: note it,
    the sync will fail that day.
  </p>

  <h3>Jira Cloud</h3>
  <table>
    <thead><tr><th>Field</th><th>Value</th></tr></thead>
    <tbody>
      <tr><td>Site address</td><td><code>https://&lt;name&gt;.atlassian.net</code>: only Jira Cloud is supported</td></tr>
      <tr><td>Board id</td><td>the number in the address of the board: <code>…/boards/42</code></td></tr>
      <tr><td>E-mail</td><td>the one of your Atlassian account</td></tr>
      <tr><td>Token</td><td>a <strong>classic</strong> API token, made with <em>Create API token</em>: it has the rights of your account, and must be able to read the issues of the board and move them between statuses</td></tr>
    </tbody>
  </table>
  <ul>
    <li>Tower shows the <strong>unfinished</strong> issues of the board, with its columns, in their order.</li>
    <li>Only issues <strong>assigned to the token's account</strong> send events: the others show without sending anything.</li>
    <li>Dragging an issue to another column runs the matching Jira transition; when several statuses fit, Tower asks which one.</li>
  </ul>

  <h3>Bitbucket Cloud</h3>
  <table>
    <thead><tr><th>Field</th><th>Value</th></tr></thead>
    <tbody>
      <tr><td>Workspace</td><td>the id of the Bitbucket workspace, as it shows in the address: <code>bitbucket.org/&lt;workspace&gt;/…</code></td></tr>
      <tr><td>E-mail</td><td>the one of your Atlassian account</td></tr>
      <tr><td>Token</td><td>an API token <strong>with scopes</strong>, made with <em>Create API token with scopes</em>, Bitbucket app, scopes <code>read:user:bitbucket</code>, <code>read:pullrequest:bitbucket</code> and <code>read:repository:bitbucket</code></td></tr>
    </tbody>
  </table>
  <ul>
    <li>Tower reads the <strong>open merge requests you authored</strong> in this workspace, across all repositories.</li>
    <li>For each: pipeline, approvals and requested changes, comments, target branch, last commit.</li>
    <li>A closed merge request stays shown 30 days, then it is removed from Tower.</li>
  </ul>

  <h3>Removing an integration</h3>
  <p>
    The <em>Delete</em> button of the tab removes the token, the settings and the data read (issues or merge requests).
    The <em>Clear a section</em> block of the Settings does the same when the section is hidden and its tab no longer
    shows. Tasks created from an issue stay, without the link.
  </p>
</template>
