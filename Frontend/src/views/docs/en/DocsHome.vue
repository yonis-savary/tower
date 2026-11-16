<script setup lang="ts">
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()
</script>

<template>
  <h2>Documentation</h2>
  <p class="lead">How to connect your devices to Tower, and how to make them react to what happens in your workspaces.</p>

  <h3>In a nutshell</h3>
  <p>
    Tower gathers your tasks, your Jira tickets and your merge requests, and sends <strong>events</strong> to the connected
    devices. Each device runs the <strong>connector</strong>, a small Python program that opens the connection to Tower
    itself: no port needs to be opened on the device.
  </p>
  <p>
    When an event arrives, the connector runs a <strong>handler</strong>, a command of your choice. The basic handler
    shipped with Tower, for instance, opens a Claude Code discussion about a Jira ticket.
  </p>

  <h3>Getting started</h3>
  <ol>
    <li>Open the <strong>Devices</strong> dialog, <em>Connect a device</em> tab.</li>
    <li>Download the connector, and the basic handler if you want Claude discussions.</li>
    <li>Create a configuration: it gives the file that identifies the device to Tower.</li>
    <li>Run the connector with this file, by hand or <a href="#" @click.prevent="$emit('open', 'linux-service')">as a service</a>.</li>
  </ol>

  <h3>Pages</h3>
  <ul>
    <li>
      <a href="#" @click.prevent="$emit('open', 'connector')">Connector</a>: running it, choosing the events it receives,
      writing handlers and what they receive.
    </li>
    <li>
      <a href="#" @click.prevent="$emit('open', 'events')">Events</a>: everything Tower sends to the devices, when, and with
      which data.
    </li>
    <li>
      <a href="#" @click.prevent="$emit('open', 'webhooks')">Webhooks</a>: have GitHub, Bitbucket, your CI or a script
      launch events, with a secret or a signature.
    </li>
    <li>
      <a href="#" @click.prevent="$emit('open', 'integrations')">Integrations</a>: plugging in Jira and Bitbucket, which token
      to create and what Tower reads.
    </li>
    <li>
      <a href="#" @click.prevent="$emit('open', 'linux-service')">Run the connector as a Linux service</a>: restart it
      automatically with each session, with systemd.
    </li>
    <li>
      <a href="#" @click.prevent="$emit('open', 'linux-server')">The connector on a headless server</a>: start it at boot and
      keep it running once your SSH sessions close, with its handlers and the commands that need root.
    </li>
  </ul>
</template>
