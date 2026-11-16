<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()

const withSecret = `
curl -X POST 'https://tower.example.com/api/hooks/<uuid>' \\
  -H 'Content-Type: application/json' \\
  -H 'X-Tower-Secret: whsec_…' \\
  -d '{"env": "prod"}'
`

const withSignature = `
body='{"env": "prod"}'
signature=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$TOWER_SECRET" | sed 's/^.* //')

curl -X POST 'https://tower.example.com/api/hooks/<uuid>' \\
  -H 'Content-Type: application/json' \\
  -H "X-Hub-Signature-256: sha256=$signature" \\
  -d "$body"
`
</script>

<template>
  <h2>Webhooks</h2>
  <p class="lead">Let an external service (CI, forge, home automation, script…) launch events to your devices.</p>

  <h3>How it works</h3>
  <ul>
    <li>
      A webhook is an address, <code>POST /api/hooks/&lt;uuid&gt;</code>, with a list of event types (1 to 20, exact types,
      no <code>*</code>).
    </li>
    <li>
      Each accepted call launches <strong>all</strong> its events, in order, with the body of the call as payload. The body
      must be a JSON object of <strong>32 KiB</strong> at most; an empty body gives <code>{}</code>.
    </li>
    <li>
      The events show in the History as <em>Event launched</em>, with the name of the webhook, and are delivered like any
      other: see <a href="#" @click.prevent="$emit('open', 'connector')">Connector</a>.
    </li>
  </ul>

  <h3>The secret</h3>
  <p>
    A webhook is <strong>secured</strong> by default. Its secret (<code>whsec_…</code>) is shown only once, when created or
    regenerated, which invalidates the previous one at once. A call proves it one of these ways:
  </p>
  <table>
    <thead><tr><th>Header</th><th>Content</th></tr></thead>
    <tbody>
      <tr><td><code>X-Tower-Secret</code></td><td>the secret as is</td></tr>
      <tr><td><code>X-Hub-Signature-256</code></td><td><code>sha256=</code> followed by the HMAC-SHA256 of the body, in hexadecimal, made with the secret (GitHub)</td></tr>
      <tr><td><code>X-Hub-Signature</code></td><td>the same signature (Bitbucket)</td></tr>
    </tbody>
  </table>
  <CodeBlock :code="withSecret" language="bash" filename="with the secret" />
  <CodeBlock :code="withSignature" language="bash" filename="with a signature" />
  <p>
    A webhook can also be created <strong>without a secret</strong>, for a service able to send none of these headers: its
    address alone then triggers it. This is chosen at creation and never changes; the list shows it with an open lock.
  </p>

  <h3>Answers</h3>
  <table>
    <thead><tr><th>Code</th><th>Meaning</th></tr></thead>
    <tbody>
      <tr><td>204</td><td>events launched</td></tr>
      <tr><td>401</td><td>missing or wrong secret or signature</td></tr>
      <tr><td>403</td><td>webhook disabled</td></tr>
      <tr><td>404</td><td>unknown address</td></tr>
      <tr><td>422</td><td>body that is not a JSON object, or bigger than 32 KiB</td></tr>
    </tbody>
  </table>
  <p>
    Each call to a known address is kept 31 days: the <em>Calls</em> button of the webhook, with the status, IP,
    <code>User-Agent</code> and body (for an authenticated call only). Refused calls also show on the Home page.
  </p>

  <h3>GitHub</h3>
  <ol>
    <li>In the repository: <em>Settings → Webhooks → Add webhook</em>.</li>
    <li><em>Payload URL</em>: the address of the webhook; <em>Content type</em>: <code>application/json</code>, otherwise the call is refused (422).</li>
    <li><em>Secret</em>: the secret of the webhook. GitHub then signs each call in <code>X-Hub-Signature-256</code>.</li>
    <li>Pick the GitHub events to send: the body your handlers receive is GitHub's.</li>
  </ol>
  <p class="note">
    <code>push</code> events of a big commit often go over 32 KiB and are then refused. Prefer lighter events
    (<code>workflow_run</code>, <code>release</code>…) or a <code>curl</code> call from your CI.
  </p>

  <h3>Bitbucket Cloud</h3>
  <ol>
    <li>In the repository: <em>Repository settings → Webhooks → Add webhook</em>.</li>
    <li><em>URL</em>: the address of the webhook; <em>Secret</em>: the secret of the webhook. Bitbucket then signs each call in <code>X-Hub-Signature</code>.</li>
    <li>Pick the <em>Triggers</em> to send.</li>
  </ol>

  <h3>GitLab and others</h3>
  <p>
    GitLab sends its <em>Secret token</em> in <code>X-Gitlab-Token</code>, which Tower does not read. For GitLab, or any
    service able neither to send a chosen header nor to sign the body, two ways: a webhook without a secret, or a
    <code>curl</code> call from the pipeline, sending <code>X-Tower-Secret</code> itself.
  </p>
</template>
