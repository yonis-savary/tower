<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()

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
    Un appareil reçoit les événements que sa configuration <strong>écoute</strong>, et le connecteur lance pour chacun les
    commandes de ses handlers, avec le message complet en JSON sur leur entrée standard :
    <code>type</code>, <code>id</code> (ligne d'historique), <code>delivery</code>, <code>workspace</code>,
    <code>event</code> et <code>payload</code>. Tout est détaillé dans
    <a href="#" @click.prevent="$emit('open', 'connector')">Connecteur</a> ; cette page décrit le <code>payload</code> de
    chaque événement.
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
      <tr><td><code>sprint.opened</code></td><td>il devient actif, à côté des autres sprints actifs</td></tr>
      <tr><td><code>sprint.closed</code></td><td>il est clôturé</td></tr>
      <tr><td><code>sprint.link_added</code>, <code>sprint.link_updated</code>, <code>sprint.link_removed</code></td><td>un lien du sprint est ajouté, modifié ou retiré ; <code>link</code> : son libellé, ou son adresse</td></tr>
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

  <h3>Événements planifiés</h3>
  <p>
    Depuis l'écran Automation, onglet Planifications : un type et un payload, lancés chaque minute, toutes les N minutes, chaque heure, toutes les N heures, chaque jour,
    semaine ou mois, ou une seule fois à une date donnée. Les heures sont celles du serveur, à la minute près. Un
    déclenchement manqué pendant un arrêt du serveur n'est pas rattrapé : une planification ponctuelle dont la date est
    passée est simplement désactivée. Dans l'Historique, ils apparaissent comme <em>Évènement lancé</em>, par la
    planification.
  </p>

  <h3>Ce qui n'est pas envoyé</h3>
  <p>
    Les connexions et déconnexions d'appareils, et la création ou la révocation des configurations, sont seulement écrites
    dans l'Historique.
  </p>
</template>

