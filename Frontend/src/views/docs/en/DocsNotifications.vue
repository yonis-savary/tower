<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()

const keys = `
php do app:push-keys
# VAPID_PUBLIC_KEY=BAgT…
# VAPID_PRIVATE_KEY=VTyN…
`

const env = `
VAPID_PUBLIC_KEY=BAgT…
VAPID_PRIVATE_KEY=VTyN…
VAPID_SUBJECT=mailto:you@example.com
`
</script>

<template>
  <h2>Notifications</h2>
  <p class="lead">Get a notification on your phone or your computer when a chosen event happens, without a connector.</p>

  <h3>Enable a browser</h3>
  <ul>
    <li>
      In <strong>Automation → Notifications</strong>, click <em>Enable notifications</em> and accept the request of the browser.
      Do it in each workspace, on each device and each browser where you want them.
    </li>
    <li>The send button next to each browser sends a test notification.</li>
    <li>
      A browser only receives the notifications of the workspaces where you enabled it: your personal workspace can stay on
      your phone and off your work computer. It belongs to the last user logged in on it: what someone else enabled there
      is removed.
    </li>
  </ul>

  <h3>iPhone and iPad</h3>
  <p>
    Safari (iOS and iPadOS 16.4 or later) only receives notifications in Tower <strong>added to the home screen</strong>: Share
    button, then <em>Add to Home Screen</em>. Open Tower from that icon, then enable the notifications.
  </p>

  <h3>Rules</h3>
  <ul>
    <li>
      A rule is yours only, in one workspace: each member chooses what they receive. Leaving a workspace deletes your rules of
      that workspace.
    </li>
    <li>
      Its patterns have the syntax of the device configurations (see
      <a href="#" @click.prevent="$emit('open', 'events')">Events</a>): <code>task.*</code>, <code>pr.pipeline.failed</code>,
      <code>*</code> for everything, 20 at most.
    </li>
    <li>An event matching several of your rules sends one notification per rule.</li>
    <li>A new notification of the same rule and the same event type replaces the previous one.</li>
  </ul>

  <h3>Title and body</h3>
  <table>
    <thead><tr><th>Choice</th><th>Gives</th></tr></thead>
    <tbody>
      <tr><td>Default</td><td>title: the name of the workspace and the type of the event; body: <code>payload.title</code>, or else a summary of the payload</td></tr>
      <tr><td>Text</td><td>the value typed, as is</td></tr>
      <tr><td>Payload key</td><td>a field of the payload, nested fields with dots (<code>author.name</code>); an object or a list is written as JSON</td></tr>
    </tbody>
  </table>
  <p>
    A key missing from the payload, or empty, gives way to the default text. The title is cut at 100 characters, the body at
    300. The preview of the form shows the result with an example event.
  </p>
  <p>A click on the notification opens Tower on the line of the event, in the History.</p>

  <h3>Server setup</h3>
  <p>Notifications go through Web Push: without VAPID keys, the Notifications tab does not show.</p>
  <CodeBlock :code="keys" language="bash" filename="generate the keys, once" />
  <CodeBlock :code="env" language="bash" filename=".env" />
  <ul>
    <li>Changing the keys invalidates every enabled browser: each one must be enabled again.</li>
    <li>
      Web Push requires HTTPS, except on <code>localhost</code>. Notifications are sent by the <code>push</code> service of
      <code>docker-compose.yml</code>; a notification older than an hour is no longer delivered.
    </li>
  </ul>
</template>
