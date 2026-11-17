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
VAPID_SUBJECT=mailto:toi@example.com
`
</script>

<template>
  <h2>Notifications</h2>
  <p class="lead">Recevoir une notification sur ton téléphone ou ton ordinateur quand un événement choisi se produit, sans connecteur.</p>

  <h3>Activer un navigateur</h3>
  <ul>
    <li>
      Dans <strong>Automation → Notifications</strong>, clique sur <em>Activer les notifications</em> et accepte la demande du
      navigateur. À faire dans chaque workspace, sur chaque appareil et chaque navigateur où tu veux les recevoir.
    </li>
    <li>Le bouton d'envoi à côté de chaque navigateur envoie une notification de test.</li>
    <li>
      Un navigateur ne reçoit que les notifications des workspaces où tu l'as activé : ton workspace perso peut rester sur
      ton téléphone et hors de ton ordinateur pro. Il appartient au dernier utilisateur connecté dessus : ce qu'un autre y
      avait activé est retiré.
    </li>
  </ul>

  <h3>iPhone et iPad</h3>
  <p>
    Safari (iOS et iPadOS 16.4 ou plus) ne reçoit les notifications que dans Tower <strong>ajouté à l'écran d'accueil</strong> :
    bouton Partager, puis <em>Sur l'écran d'accueil</em>. Ouvre Tower depuis cette icône, puis active les notifications.
  </p>

  <h3>Règles</h3>
  <ul>
    <li>
      Une règle appartient à toi seul, dans un workspace : chaque membre choisit ce qu'il reçoit. Quitter un workspace supprime
      tes règles de ce workspace.
    </li>
    <li>
      Ses motifs ont la même syntaxe que l'écoute des configurations d'appareil (voir
      <a href="#" @click.prevent="$emit('open', 'events')">Événements</a>) : <code>task.*</code>, <code>pr.pipeline.failed</code>,
      <code>*</code> pour tout, 20 au plus.
    </li>
    <li>Un événement qui correspond à plusieurs de tes règles envoie une notification par règle.</li>
    <li>Une nouvelle notification d'une même règle et d'un même type d'événement remplace la précédente.</li>
  </ul>

  <h3>Titre et texte</h3>
  <table>
    <thead><tr><th>Choix</th><th>Donne</th></tr></thead>
    <tbody>
      <tr><td>Par défaut</td><td>titre : le nom du workspace et le type de l'événement ; texte : <code>payload.title</code>, sinon un résumé du payload</td></tr>
      <tr><td>Texte</td><td>la valeur saisie, telle quelle</td></tr>
      <tr><td>Clé du payload</td><td>un champ du payload, champs imbriqués avec des points (<code>author.name</code>) ; un objet ou une liste est écrit en JSON</td></tr>
    </tbody>
  </table>
  <p>
    Une clé absente du payload, ou vide, laisse place au texte par défaut. Le titre est coupé à 100 caractères, le texte à 300.
    L'aperçu du formulaire montre le résultat avec un événement d'exemple.
  </p>
  <p>Un clic sur la notification ouvre Tower sur la ligne de l'événement, dans l'Historique.</p>

  <h3>Mise en place du serveur</h3>
  <p>Les notifications passent par Web Push : sans clés VAPID, l'onglet Notifications n'apparaît pas.</p>
  <CodeBlock :code="keys" language="bash" filename="générer les clés, une seule fois" />
  <CodeBlock :code="env" language="bash" filename=".env" />
  <ul>
    <li>Changer les clés invalide tous les navigateurs activés : chacun devra être activé à nouveau.</li>
    <li>
      Web Push exige HTTPS, sauf sur <code>localhost</code>. Les notifications sont envoyées par le service <code>push</code> de
      <code>docker-compose.yml</code> ; une notification vieille d'une heure n'est plus délivrée.
    </li>
  </ul>
</template>
