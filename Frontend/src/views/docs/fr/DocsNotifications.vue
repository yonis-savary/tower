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
      Dans <strong>Automatisations → Notifications</strong>, clique sur <em>Activer les notifications</em> et accepte la demande du
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
  <p>
    Le titre et le texte sont des modèles, avec les mêmes variables que les handlers du connecteur, par exemple
    <code>{payload.count} nouvelles tâches de {payload.author.name}</code>.
  </p>
  <table>
    <thead><tr><th>Variable</th><th>Donne</th></tr></thead>
    <tbody>
      <tr><td><code>{event}</code></td><td>le type de l'événement</td></tr>
      <tr><td><code>{workspace}</code></td><td>le nom du workspace</td></tr>
      <tr><td><code>{payload.title}</code></td><td>un champ du payload, champs imbriqués avec des points (<code>{payload.author.name}</code>) ; un objet ou une liste est écrit en JSON</td></tr>
      <tr><td><code>{payload}</code></td><td>tout le payload, en JSON</td></tr>
    </tbody>
  </table>
  <ul>
    <li>
      Un champ du payload se préfixe toujours par <code>payload.</code> : <code>{title}</code> n'est pas une variable et reste
      écrit tel quel, il faut écrire <code>{payload.title}</code>.
    </li>
    <li>Une variable sans valeur (champ absent ou nul, nom inconnu) reste écrite telle quelle, comme dans le connecteur.</li>
    <li>
      Un champ laissé vide, ou un modèle qui ne donne que du vide, prend le texte par défaut : le nom du workspace et le type
      de l'événement pour le titre ; <code>payload.title</code>, sinon un résumé du payload, pour le texte.
    </li>
    <li>Le titre est coupé à 100 caractères, le texte à 300. L'aperçu du formulaire montre le résultat avec un événement d'exemple.</li>
  </ul>
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
