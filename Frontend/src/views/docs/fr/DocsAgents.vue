<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'
import { claudeCodeCommand, claudeDesktopConfig, mcpJsonConfig, mcpUrl } from '../../../utils/mcpSetup'
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()

const url = mcpUrl()
const token = 'tower_…'
</script>

<template>
  <h2>Agents IA (MCP)</h2>
  <p class="lead">Brancher Claude Code, Claude Desktop ou tout client MCP sur un workspace Tower.</p>

  <h3>Principe</h3>
  <ul>
    <li>
      Tower est un serveur <strong>MCP</strong> (Model Context Protocol) à l'adresse <code>{{ url }}</code>. Un agent y lit
      les tâches, les sprints, les tickets Jira, les merge requests et l'historique, et peut agir : créer ou déplacer une
      tâche, changer le statut d'un ticket, lancer un évènement vers tes appareils.
    </li>
    <li>
      Il se connecte avec un <strong>jeton personnel</strong>, lié à un workspace : il agit en ton nom, sur ce workspace
      seulement. Ses actions apparaissent dans l'Historique avec le nom du jeton.
    </li>
    <li>Rien à installer côté serveur : une adresse et un jeton suffisent.</li>
  </ul>

  <h3>1. Ouvrir le workspace aux agents</h3>
  <p>
    Dans la gestion des workspaces, onglet <em>Fonctionnalités</em>, <em>Accès des agents IA</em> fixe ce qu'un agent peut
    faire dans le workspace, quel que soit son jeton :
  </p>
  <table>
    <thead><tr><th>Niveau</th><th>Effet</th></tr></thead>
    <tbody>
      <tr><td><code>search_workspace</code></td><td>chercher un texte dans tout le workspace : merge requests, tickets Jira, tâches, quick-actions et documentation, 5 résultats par type, les non terminés d'abord</td></tr>
      <tr><td>Bloqué</td><td>aucune connexion, aucun jeton ne peut être créé</td></tr>
      <tr><td>Lecture</td><td>les outils de lecture seulement, même pour un jeton en écriture (par défaut)</td></tr>
      <tr><td>Lecture et écriture</td><td>les outils d'écriture pour les jetons qui en ont le droit</td></tr>
    </tbody>
  </table>

  <h3>2. Créer un jeton</h3>
  <p>
    Dans <strong>Paramètres → Agents IA</strong>, sur le workspace voulu : un nom (l'appareil ou l'agent), des droits
    (lecture seule, ou lecture et écriture) et une expiration. Le jeton n'est affiché <strong>qu'une fois</strong>, avec les
    commandes ci-dessous déjà remplies. Il se révoque d'un clic, et cesse de marcher si tu quittes le workspace.
  </p>
  <div class="note">
    Un jeton donne accès au workspace en ton nom : garde-le comme un mot de passe, et préfère la lecture seule quand
    l'agent n'a pas besoin d'agir. Deux workspaces, c'est deux jetons et deux serveurs MCP côté agent.
  </div>

  <h3>3. Brancher l'agent</h3>
  <p><strong>Claude Code</strong>, une commande :</p>
  <CodeBlock :code="claudeCodeCommand(url, token)" language="bash" filename="terminal" />
  <p>
    <code>--scope user</code> l'ajoute à tous tes projets. Remplace-le par <code>--scope local</code> pour le seul projet
    courant, ou par <code>--scope project</code> pour l'écrire dans le <code>.mcp.json</code> du projet (sans y laisser le
    jeton si le fichier est versionné). <code>/mcp</code> dans Claude Code montre si la connexion passe, et
    <code>claude mcp remove tower</code> le retire.
  </p>

  <p><strong>Claude Desktop</strong> ne lance que des programmes locaux depuis sa configuration : <code>mcp-remote</code> (Node.js requis) fait le relais. Dans <em>Settings → Developer → Edit Config</em> :</p>
  <CodeBlock :code="claudeDesktopConfig(url, token)" language="json" filename="claude_desktop_config.json" />
  <p>Redémarre Claude Desktop ensuite.</p>

  <p><strong>Autres clients</strong> (Cursor, VS Code, …) : la plupart lisent ce format, avec le transport HTTP et un en-tête :</p>
  <CodeBlock :code="mcpJsonConfig(url, token)" language="json" filename=".mcp.json" />

  <h3>Les outils</h3>
  <p>
    Un outil d'écriture commence sa description par <code>[write]</code> : les clients demandent en général confirmation
    avant de l'appeler.
  </p>
  <table>
    <thead><tr><th>Outil</th><th>Effet</th></tr></thead>
    <tbody>
      <tr><td><code>list_tasks</code>, <code>list_sprints</code>, <code>list_task_history</code></td><td>les tâches, les sprints et leurs liens, l'historique d'une tâche</td></tr>
      <tr><td><code>create_task</code>, <code>update_task</code>, <code>move_task</code>, <code>add_sprint_link</code></td><td>écriture : créer, modifier (titre, description, lien), changer de statut ou de sprint, ajouter un lien à un sprint</td></tr>
      <tr><td><code>preview_task_notes</code>, <code>create_tasks_from_notes</code></td><td>lire des notes de réunion (un titre par ligne, la description indentée en dessous) et voir les tâches qu'elles décrivent ; écriture : les créer toutes d'un coup, 50 au plus</td></tr>
      <tr><td><code>list_agenda_items</code></td><td>les éléments de l'agenda du workspace entre deux dates (62 jours au plus), avec leurs participants ; filtrables par participants. Un élément récurrent y figure une fois par occurrence, avec le jour de l'occurrence</td></tr>
      <tr><td><code>create_agenda_item</code>, <code>update_agenda_item</code></td><td>écriture : ajouter un élément à l'agenda, le modifier (titre, dates, journée entière, lien, couleur, participants, membres du workspace, récurrence : chaque jour, semaine, mois ou an, jusqu'à un jour) ; pour un élément récurrent, une occurrence seule, avec les suivantes ou toutes</td></tr>
      <tr><td><code>list_jira_profiles</code></td><td>les profils Jira (board, nom, filtre JQL, colonnes) et le compte Jira du token</td></tr>
      <tr><td><code>list_jira_issues</code>, <code>list_jira_transitions</code></td><td>les tickets Jira suivis et leurs sous-tâches (filtrables : par profil, les miens, par catégorie de statut), les transitions possibles d'un ticket</td></tr>
      <tr><td><code>get_jira_issue</code>, <code>get_jira_attachment</code></td><td>un ticket lu dans Jira par sa clé, sur le board ou non (description, commentaires, pièces jointes), et une pièce jointe de 5 Mio au plus : une image arrive comme une image</td></tr>
      <tr><td><code>transition_jira_issue</code>, <code>create_task_from_jira_issue</code></td><td>écriture : changer le statut dans Jira, créer une tâche liée</td></tr>
      <tr><td><code>list_pull_requests</code>, <code>get_pull_request</code></td><td>les merge requests ouvertes et récemment fermées, et l'une d'elles lue dans Bitbucket : sa description et ses commentaires (fichier et ligne d'un commentaire en ligne, fil résolu ou non ; <code>unresolved</code> ne garde que les fils ouverts)</td></tr>
      <tr><td><code>list_history</code>, <code>list_event_deliveries</code></td><td>l'historique du workspace, les appareils qu'un évènement a touchés</td></tr>
      <tr><td><code>suggest_event_types</code>, <code>list_quick_actions</code>, <code>list_scheduled_events</code></td><td>les types d'évènements connus, les quick-actions, les planifications</td></tr>
      <tr><td><code>trigger_event</code>, <code>trigger_quick_action</code></td><td>écriture : lancer un évènement vers les appareils</td></tr>
      <tr><td><code>list_doc_pages</code>, <code>get_doc_page</code></td><td>les pages de la documentation sans leur contenu (filtrables : recherche, tag, archivées), puis une page en Markdown avec sa version</td></tr>
      <tr><td><code>list_doc_page_versions</code>, <code>get_doc_page_version</code>, <code>get_doc_image</code></td><td>les versions d'une page gardées 31 jours et leurs auteurs, une version complète, une image de la documentation : elle arrive comme une image</td></tr>
      <tr><td><code>create_doc_page</code>, <code>update_doc_page</code>, <code>edit_doc_page</code>, <code>upload_doc_image</code></td><td>écriture : créer une page, la modifier en entier (titre, contenu, tags, parent, archivage), remplacer quelques passages, envoyer une image en base64. Avec la version lue, un changement fait entre-temps est refusé plutôt qu'écrasé</td></tr>
    </tbody>
  </table>
  <p>
    Rien ne touche aux secrets ni aux accès (configurations, webhooks, intégrations, membres) et rien ne supprime. Voir
    <a href="#" @click.prevent="$emit('open', 'events')">Évènements</a> pour ce que reçoivent les appareils.
  </p>

  <h3>En cas d'échec</h3>
  <table>
    <thead><tr><th>Réponse</th><th>Cause</th></tr></thead>
    <tbody>
      <tr><td><code>401</code></td><td>jeton absent, inconnu, révoqué ou expiré, compte désactivé, ou tu n'es plus membre du workspace</td></tr>
      <tr><td><code>403</code></td><td>le workspace bloque les agents IA</td></tr>
      <tr><td>outil absent de la liste</td><td>un outil d'écriture avec un jeton en lecture seule, ou un workspace en lecture</td></tr>
    </tbody>
  </table>
</template>
