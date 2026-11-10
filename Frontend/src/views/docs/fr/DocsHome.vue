<script setup lang="ts">
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()
</script>

<template>
  <h2>Documentation</h2>
  <p class="lead">Comment brancher tes appareils sur Tower, et comment les faire réagir à ce qui se passe dans tes workspaces.</p>

  <h3>En deux mots</h3>
  <p>
    Tower centralise tes tâches, tes tickets Jira et tes merge requests, et envoie des <strong>événements</strong> aux
    appareils connectés. Chaque appareil fait tourner le <strong>connecteur</strong>, un petit programme Python qui ouvre
    lui-même la connexion vers Tower : aucun port n'est à ouvrir côté appareil.
  </p>
  <p>
    Quand un événement arrive, le connecteur lance un <strong>handler</strong>, une commande de ton choix. Le handler de base
    fourni par Tower ouvre par exemple une discussion Claude Code sur un ticket Jira.
  </p>

  <h3>Pour commencer</h3>
  <ol>
    <li>Ouvre la modale <strong>Appareils</strong>, onglet <em>Connecter un appareil</em>.</li>
    <li>Télécharge le connecteur, et le handler de base si tu veux les discussions Claude.</li>
    <li>Crée une configuration : elle donne le fichier qui identifie l'appareil auprès de Tower.</li>
    <li>Lance le connecteur avec ce fichier, à la main ou <a href="#" @click.prevent="$emit('open', 'linux-service')">en service</a>.</li>
  </ol>

  <h3>Pages</h3>
  <ul>
    <li>
      <a href="#" @click.prevent="$emit('open', 'events')">Événements</a> : tout ce que Tower envoie aux appareils, quand, et
      avec quelles données.
    </li>
    <li>
      <a href="#" @click.prevent="$emit('open', 'linux-service')">Lancer le connecteur en service sous Linux</a> : le relancer
      automatiquement à chaque session, avec systemd.
    </li>
    <li>
      <a href="#" @click.prevent="$emit('open', 'linux-server')">Le connecteur sur un serveur sans écran</a> : le démarrer au
      boot et le garder en marche après la fermeture de tes sessions SSH, avec ses handlers et les commandes qui demandent root.
    </li>
  </ul>
</template>
