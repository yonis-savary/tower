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
  <p class="lead">Laisser un service externe (CI, forge, domotique, script…) lancer des événements vers tes appareils.</p>

  <h3>Principe</h3>
  <ul>
    <li>
      Un webhook est une adresse, <code>POST /api/hooks/&lt;uuid&gt;</code>, avec une liste de types d'événements (1 à 20,
      types exacts, sans <code>*</code>).
    </li>
    <li>
      Chaque appel accepté lance <strong>tous</strong> ses événements, dans l'ordre, avec le corps de l'appel pour payload.
      Le corps doit être un objet JSON de <strong>32 Kio</strong> au plus ; un corps vide donne <code>{}</code>.
    </li>
    <li>
      Les événements lancés apparaissent dans l'Historique comme <em>Évènement lancé</em>, avec le nom du webhook, et sont
      livrés comme tous les autres : voir <a href="#" @click.prevent="$emit('open', 'connector')">Connecteur</a>.
    </li>
  </ul>

  <h3>Le secret</h3>
  <p>
    Un webhook est <strong>sécurisé</strong> par défaut. Son secret (<code>whsec_…</code>) n'est affiché qu'une fois, à la
    création ou à sa régénération, qui invalide l'ancien aussitôt. L'appel le prouve de l'une de ces façons :
  </p>
  <table>
    <thead><tr><th>En-tête</th><th>Contenu</th></tr></thead>
    <tbody>
      <tr><td><code>X-Tower-Secret</code></td><td>le secret tel quel</td></tr>
      <tr><td><code>X-Hub-Signature-256</code></td><td><code>sha256=</code> suivi du HMAC-SHA256 du corps, en hexadécimal, calculé avec le secret (GitHub)</td></tr>
      <tr><td><code>X-Hub-Signature</code></td><td>la même signature (Bitbucket)</td></tr>
    </tbody>
  </table>
  <CodeBlock :code="withSecret" language="bash" filename="avec le secret" />
  <CodeBlock :code="withSignature" language="bash" filename="avec une signature" />
  <p>
    Un webhook peut aussi être créé <strong>sans secret</strong>, pour un service qui ne sait envoyer aucun de ces en-têtes :
    son adresse seule le déclenche alors. Ce choix se fait à la création et ne change plus ; la liste le signale par un
    cadenas ouvert.
  </p>

  <h3>Réponses</h3>
  <table>
    <thead><tr><th>Code</th><th>Sens</th></tr></thead>
    <tbody>
      <tr><td>204</td><td>événements lancés</td></tr>
      <tr><td>401</td><td>secret ou signature absent ou faux</td></tr>
      <tr><td>403</td><td>webhook désactivé</td></tr>
      <tr><td>404</td><td>adresse inconnue</td></tr>
      <tr><td>422</td><td>corps qui n'est pas un objet JSON, ou plus gros que 32 Kio</td></tr>
    </tbody>
  </table>
  <p>
    Chaque appel à une adresse connue est gardé 31 jours : bouton <em>Appels</em> du webhook, avec le statut, l'IP, le
    <code>User-Agent</code> et le corps (seulement pour un appel authentifié). Les appels refusés s'affichent aussi sur
    l'Accueil.
  </p>

  <h3>GitHub</h3>
  <ol>
    <li>Dans le dépôt : <em>Settings → Webhooks → Add webhook</em>.</li>
    <li><em>Payload URL</em> : l'adresse du webhook ; <em>Content type</em> : <code>application/json</code>, sinon l'appel est refusé (422).</li>
    <li><em>Secret</em> : le secret du webhook. GitHub signe alors chaque appel dans <code>X-Hub-Signature-256</code>.</li>
    <li>Choisis les événements GitHub à envoyer : le corps reçu par tes handlers est celui de GitHub.</li>
  </ol>
  <p class="note">
    Les événements <code>push</code> d'un gros commit dépassent souvent 32 Kio et sont alors refusés. Préfère des
    événements plus légers (<code>workflow_run</code>, <code>release</code>…) ou un appel <code>curl</code> depuis ta CI.
  </p>

  <h3>Bitbucket Cloud</h3>
  <ol>
    <li>Dans le dépôt : <em>Repository settings → Webhooks → Add webhook</em>.</li>
    <li><em>URL</em> : l'adresse du webhook ; <em>Secret</em> : le secret du webhook. Bitbucket signe alors chaque appel dans <code>X-Hub-Signature</code>.</li>
    <li>Choisis les <em>Triggers</em> à envoyer.</li>
  </ol>

  <h3>GitLab et les autres</h3>
  <p>
    GitLab envoie son <em>Secret token</em> dans <code>X-Gitlab-Token</code>, que Tower ne lit pas. Pour GitLab, ou pour
    tout service qui ne sait ni envoyer un en-tête choisi ni signer le corps, deux solutions : un webhook sans secret, ou
    un appel <code>curl</code> depuis le pipeline, qui envoie lui-même <code>X-Tower-Secret</code>.
  </p>
</template>
