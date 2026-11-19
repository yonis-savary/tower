<script setup lang="ts">
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()
</script>

<template>
  <h2>Intégrations</h2>
  <p class="lead">Brancher Jira Cloud et Bitbucket Cloud sur un workspace, et ce que Tower en lit.</p>

  <h3>Ce qu'il faut savoir</h3>
  <ul>
    <li>
      Chaque intégration se règle dans <strong>Paramètres</strong>, onglet Jira ou Bitbucket, visible quand la section
      correspondante est cochée dans le workspace.
    </li>
    <li>
      Tower lit tes outils <strong>toutes les 5 minutes</strong>, ou tout de suite avec le bouton <em>Synchroniser</em>, et
      transforme chaque différence en événement (voir <a href="#" @click.prevent="$emit('open', 'events')">Événements</a>).
    </li>
    <li>
      Tower agit avec <strong>ton</strong> compte : il voit ce que le token voit, et une transition Jira faite depuis Tower
      est faite en ton nom.
    </li>
    <li>
      Le token est chiffré en base et n'est jamais renvoyé par l'API. Pour le remplacer, saisis-en un nouveau ; laisse le
      champ vide pour garder l'actuel.
    </li>
    <li>
      Si une synchronisation échoue (token expiré, droits manquants…), l'erreur s'affiche dans l'onglet de l'intégration
      jusqu'à la synchronisation réussie suivante.
    </li>
  </ul>

  <h3>Créer un API token Atlassian</h3>
  <p>
    Jira et Bitbucket utilisent un API token de ton compte Atlassian, avec l'e-mail de ce compte. Il se crée sur
    <code>id.atlassian.com</code>, <em>Sécurité → Créer et gérer les tokens API</em>. Un token a une date d'expiration :
    note-la, la synchronisation échouera le jour venu.
  </p>

  <h3>Jira Cloud</h3>
  <table>
    <thead><tr><th>Champ</th><th>Valeur</th></tr></thead>
    <tbody>
      <tr><td>Adresse du site</td><td><code>https://&lt;nom&gt;.atlassian.net</code> : seul Jira Cloud est pris en charge</td></tr>
      <tr><td>E-mail</td><td>celui de ton compte Atlassian</td></tr>
      <tr><td>Token</td><td>un API token <strong>classique</strong>, créé avec <em>Créer un token API</em> : il a les droits de ton compte, et doit pouvoir lire les tickets des boards et les faire changer de statut</td></tr>
    </tbody>
  </table>
  <p>
    Une fois l'intégration enregistrée, ajoute un <strong>profil</strong> par board à suivre :
  </p>
  <table>
    <thead><tr><th>Champ</th><th>Valeur</th></tr></thead>
    <tbody>
      <tr><td>Identifiant du board</td><td>le nombre dans l'adresse du board : <code>…/boards/42</code></td></tr>
      <tr><td>Nom</td><td>facultatif : sans nom, le profil prend celui du board</td></tr>
      <tr><td>Filtre JQL</td><td>facultatif : une condition ajoutée à celle du board, sans <code>ORDER BY</code>, par exemple <code>component = Billing</code></td></tr>
    </tbody>
  </table>
  <ul>
    <li>Tower affiche les tickets <strong>non terminés</strong> d'un profil à la fois, avec les colonnes de son board, dans leur ordre.</li>
    <li>
      Un ticket montré par plusieurs profils n'est stocké et annoncé qu'une fois. Les tickets d'un nouveau profil ne sont
      pas annoncés à sa première lecture.
    </li>
    <li>
      Supprimer un profil, ou changer son board ou son filtre, retire sans les annoncer les tickets qu'aucun autre profil
      ne montre. Ceux liés à une tâche restent.
    </li>
    <li>
      Seuls les tickets <strong>assignés au compte du token</strong> émettent des événements : les autres s'affichent sans
      rien envoyer.
    </li>
    <li>
      Glisser un ticket dans une autre colonne lance la transition Jira correspondante ; quand plusieurs statuts
      conviennent, Tower demande lequel.
    </li>
  </ul>

  <h3>Bitbucket Cloud</h3>
  <table>
    <thead><tr><th>Champ</th><th>Valeur</th></tr></thead>
    <tbody>
      <tr><td>Workspace</td><td>l'identifiant du workspace Bitbucket, tel qu'il apparaît dans l'adresse : <code>bitbucket.org/&lt;workspace&gt;/…</code></td></tr>
      <tr><td>E-mail</td><td>celui de ton compte Atlassian</td></tr>
      <tr><td>Token</td><td>un API token <strong>avec scopes</strong>, créé avec <em>Créer un token API avec des scopes</em>, application Bitbucket, scopes <code>read:user:bitbucket</code>, <code>read:pullrequest:bitbucket</code> et <code>read:repository:bitbucket</code></td></tr>
    </tbody>
  </table>
  <ul>
    <li>Tower lit les merge requests <strong>ouvertes dont tu es l'auteur</strong> dans ce workspace, tous dépôts confondus.</li>
    <li>Pour chacune : pipeline, approbations et changements demandés, commentaires, branche cible, dernier commit.</li>
    <li>Une merge request fermée reste affichée 30 jours, puis elle est supprimée de Tower.</li>
  </ul>

  <h3>Retirer une intégration</h3>
  <p>
    Le bouton <em>Supprimer</em> de l'onglet retire le token, les réglages et les données lues (tickets ou merge
    requests). Le bloc <em>Vider une section</em> des Paramètres fait de même quand la section est masquée et que son
    onglet n'est plus visible. Les tâches créées depuis un ticket restent, sans lien.
  </p>
</template>
