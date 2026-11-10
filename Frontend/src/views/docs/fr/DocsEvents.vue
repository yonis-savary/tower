<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'

const envelope = `
{
  "type": "event",
  "id": 1842,
  "delivery": 3307,
  "workspace": 1,
  "event": "pr.pipeline.failed",
  "payload": {
    "source": "bitbucket",
    "id": "128",
    "repository": "team/api",
    "title": "Add the login page",
    "url": "https://bitbucket.org/team/api/pull-requests/128",
    "from": "running",
    "to": "failed"
  }
}
`

const handlers = `
{
  "handlers": {
    "task.status_changed": "notify-send Tower \\"{payload.title} : {payload.to}\\"",
    "pr.pipeline.failed": "notify-send \\"Pipeline en échec\\" \\"{payload.title}\\"",
    "jira.issue.*": "python3 ~/tower/jira.py"
  }
}
`

const taskExample = `
{
  "task": 42,
  "title": "Préparer la démo",
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
  "summary": "Corriger la connexion",
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
  "summary": "Corriger la connexion",
  "project": "Platform",
  "issue_type": "Bug",
  "priority": "High",
  "status": "In Progress",
  "sprint": "Sprint 4",
  "assignee": "Alice",
  "reporter": "Bob",
  "labels": ["auth"],
  "description": "h2. Étapes\\n...",
  "comments": [{ "author": "Bob", "created": "2026-11-10T10:00:00.000+0100", "body": "Vu en prod" }]
}
`
</script>

<template>
  <h2>Événements</h2>
  <p class="lead">Ce que Tower envoie aux appareils connectés, quand, et avec quelles données.</p>

  <h3>Recevoir un événement</h3>
  <p>
    Une configuration n'envoie à ses appareils que les événements qu'elle <strong>écoute</strong> : des motifs réglés dans
    la modale Appareils, <code>*</code> par défaut. <code>*</code> correspond à n'importe quelle suite de caractères, points
    compris : <code>task.*</code>, <code>pr.pipeline.*</code>, <code>jira.issue.status_changed</code>.
  </p>
  <p>
    Pour chaque événement reçu, le connecteur lance le handler dont le motif correspond, sans shell. Le handler reçoit le
    message complet en JSON sur son <strong>entrée standard</strong> :
  </p>
  <CodeBlock :code="envelope" language="json" />
  <p>
    <code>id</code> est la ligne d'historique de l'événement, <code>delivery</code> sa livraison à cet appareil. Le handler
    trouve aussi <code>TOWER_EVENT</code>, <code>TOWER_EVENT_ID</code>, <code>TOWER_DELIVERY</code> et
    <code>TOWER_WORKSPACE</code> dans son environnement. Dans la commande d'un handler, <code>{event}</code> et
    <code>{payload.champ}</code> sont remplacés par leur valeur, champs imbriqués avec des points :
  </p>
  <CodeBlock :code="handlers" language="json" filename="config.json" />
  <p>
    Le code de sortie et la sortie du handler reviennent à Tower : ils s'affichent dans l'Historique, sous l'événement.
  </p>

  <h3>Tâches</h3>
  <p>
    Toujours présents : <code>task</code> (identifiant), <code>title</code>, <code>status</code> et <code>sprint</code>
    (identifiant, <code>null</code> dans le backlog). Les statuts sont <code>todo</code>, <code>in_progress</code>,
    <code>in_review</code>, <code>finalizing</code>, <code>done</code> et <code>cancelled</code>.
  </p>
  <table>
    <thead><tr><th>Événement</th><th>Quand</th><th>Champs en plus</th></tr></thead>
    <tbody>
      <tr><td><code>task.created</code></td><td>une tâche est créée</td><td></td></tr>
      <tr><td><code>task.updated</code></td><td>son titre ou sa description change</td><td><code>previous_title</code></td></tr>
      <tr><td><code>task.status_changed</code></td><td>son statut change</td><td><code>from</code>, <code>to</code></td></tr>
      <tr><td><code>task.sprint_changed</code></td><td>elle change de sprint</td><td><code>from</code>, <code>to</code> : noms des sprints, <code>null</code> pour le backlog</td></tr>
    </tbody>
  </table>
  <CodeBlock :code="taskExample" language="json" filename="task.status_changed" />

  <h3>Sprints</h3>
  <p><code>sprint</code> (identifiant) et <code>name</code>.</p>
  <table>
    <thead><tr><th>Événement</th><th>Quand</th></tr></thead>
    <tbody>
      <tr><td><code>sprint.created</code></td><td>un sprint est planifié</td></tr>
      <tr><td><code>sprint.opened</code></td><td>il devient le sprint actif</td></tr>
      <tr><td><code>sprint.closed</code></td><td>il est clôturé</td></tr>
    </tbody>
  </table>

  <h3>Merge requests</h3>
  <p>
    Toujours présents : <code>source</code> (<code>bitbucket</code>), <code>id</code>, <code>repository</code>,
    <code>title</code> et <code>url</code>. Ces événements viennent de la synchronisation, toutes les 5 minutes.
  </p>
  <table>
    <thead><tr><th>Événement</th><th>Quand</th><th>Champs en plus</th></tr></thead>
    <tbody>
      <tr><td><code>pr.opened</code></td><td>une merge request s'ouvre</td><td></td></tr>
      <tr><td><code>pr.merged</code></td><td>elle est fusionnée</td><td></td></tr>
      <tr><td><code>pr.declined</code></td><td>elle se ferme autrement : refusée, remplacée, introuvable</td><td><code>state</code>, <code>null</code> si introuvable</td></tr>
      <tr><td><code>pr.pipeline.failed</code></td><td>son pipeline passe en échec</td><td><code>from</code>, <code>to</code></td></tr>
      <tr><td><code>pr.pipeline.succeeded</code></td><td>son pipeline réussit</td><td><code>from</code>, <code>to</code></td></tr>
      <tr><td><code>pr.review.approved</code></td><td>un relecteur approuve</td><td><code>reviewer</code></td></tr>
      <tr><td><code>pr.review.changes_requested</code></td><td>un relecteur demande des changements</td><td><code>reviewer</code></td></tr>
      <tr><td><code>pr.commented</code></td><td>de nouveaux commentaires arrivent</td><td><code>count</code> (les nouveaux), <code>from</code>, <code>to</code></td></tr>
      <tr><td><code>pr.pushed</code></td><td>un nouveau commit arrive sur la branche source</td><td><code>branch</code>, <code>from</code>, <code>to</code> (commits)</td></tr>
      <tr><td><code>pr.ready</code></td><td>elle quitte l'état brouillon</td><td></td></tr>
      <tr><td><code>pr.retargeted</code></td><td>sa branche cible change</td><td><code>from</code>, <code>to</code></td></tr>
    </tbody>
  </table>

  <h3>Tickets Jira</h3>
  <p>
    Toujours présents : <code>source</code> (<code>jira</code>), <code>key</code>, <code>summary</code> et
    <code>url</code>. Seuls <strong>tes</strong> tickets, ceux assignés au compte du token, émettent des événements : les
    autres tickets du board s'affichent sans rien envoyer. Synchronisation toutes les 5 minutes.
  </p>
  <table>
    <thead><tr><th>Événement</th><th>Quand</th><th>Champs en plus</th></tr></thead>
    <tbody>
      <tr><td><code>jira.issue.assigned</code></td><td>un ticket du board devient le tien</td><td></td></tr>
      <tr><td><code>jira.issue.done</code></td><td>un des tiens est terminé : catégorie « terminé » ou dernière colonne du board</td><td><code>status</code></td></tr>
      <tr><td><code>jira.issue.unassigned</code></td><td>un des tiens ne l'est plus : réassigné, sorti du board, supprimé</td><td><code>status</code>, <code>assignee</code></td></tr>
      <tr><td><code>jira.issue.status_changed</code></td><td>son statut change, dans Jira ou par un glisser-déposer dans Tower</td><td><code>from</code>, <code>to</code>, <code>from_category</code>, <code>to_category</code></td></tr>
      <tr><td><code>jira.issue.sprint_changed</code></td><td>il change de sprint</td><td><code>from</code>, <code>to</code> : noms, <code>null</code> sans sprint</td></tr>
      <tr><td><code>jira.issue.priority_changed</code></td><td>sa priorité change</td><td><code>from</code>, <code>to</code></td></tr>
      <tr><td><code>jira.issue.updated</code></td><td>son résumé ou son type change</td><td><code>fields</code>, <code>previous_summary</code></td></tr>
    </tbody>
  </table>
  <p>Les catégories valent <code>todo</code>, <code>in_progress</code> ou <code>done</code>.</p>
  <CodeBlock :code="jiraExample" language="json" filename="jira.issue.status_changed" />

  <h3>Discussion IA</h3>
  <p>
    <code>ai.discuss</code> part quand tu cliques sur <em>Discuter</em>, sur un ticket Jira ou une tâche liée à un ticket.
    Le ticket est relu dans Jira à ce moment-là ; la description et les 20 derniers commentaires sont en texte wiki Jira.
    Le handler de base ouvre une session Claude Code avec ce contenu.
  </p>
  <CodeBlock :code="discussExample" language="json" filename="ai.discuss" />

  <h3>Événements lancés à la main</h3>
  <p>
    Depuis l'écran Événements, ou par une quick-action : le type et le payload sont ceux que tu as saisis. Dans
    l'Historique, ils apparaissent comme <em>Évènement lancé</em>.
  </p>

  <h3>Ce qui n'est pas envoyé</h3>
  <p>
    Les connexions et déconnexions d'appareils, et la création ou la révocation des configurations, sont seulement écrites
    dans l'Historique.
  </p>
</template>

