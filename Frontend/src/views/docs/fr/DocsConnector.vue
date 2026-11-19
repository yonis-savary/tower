<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()

const run = `
python3 tower-connector.pyz config.json
python3 tower-connector.pyz config.json --handler-file ~/tower/handlers.json
python3 tower-connector.pyz config.json --handler "python3 ~/tower/mon-handler.py"
`

const config = `
{
  "tower_url": "wss://tower.example.com/devices",
  "client_id": "…",
  "secret": "…",
  "handlers": {
    "task.status_changed": "notify-send Tower \\"{payload.title} : {payload.to}\\"",
    "pr.pipeline.failed": "notify-send \\"Pipeline en échec\\" \\"{payload.title}\\"",
    "deploy.*": ["git -C ~/projets/api pull", "make -C ~/projets/api deploy"],
    "ai.discuss": "~/tower/tower-handler.pyz --cwd ~/projets"
  }
}
`

const envelope = `
{
  "type": "event",
  "id": 1842,
  "delivery": 3307,
  "workspace": 1,
  "event": "deploy.start",
  "payload": { "env": "prod", "services": ["api", "front"] }
}
`

const shell = `
"backup.run": "sh -c 'tar czf ~/backup.tgz ~/projets && rclone copy ~/backup.tgz remote:'"
`

const chained = `
"backup.run": {
  "run": "python3 ~/tower/backup.py {payload.env}",
  "response": { "env": "{payload.env}", "summary": "{stdout}", "code": "{exit_code}" },
  "next": {
    "type": "notify.phone",
    "payload": { "title": "Sauvegarde {payload.env} terminée", "files": "{payload.count}" }
  }
}
`
</script>

<template>
  <h2>Le connecteur</h2>
  <p class="lead">Le programme qui relie un appareil à Tower, et la façon de lui dire quoi lancer à chaque événement.</p>

  <h3>Ce qu'il faut savoir</h3>
  <ul>
    <li>Un seul fichier, <code>tower-connector.pyz</code>, qui demande Python 3.10 ou plus, sans rien à installer.</li>
    <li>
      C'est lui qui ouvre la connexion (WebSocket) vers Tower : aucun port n'est à ouvrir sur l'appareil. Coupée, elle est
      rouverte toute seule, avec une attente qui grandit jusqu'à une minute.
    </li>
    <li>
      Un appareil hors ligne au moment d'un événement ne le reçoit jamais : rien n'est mis en attente côté Tower.
    </li>
    <li>
      Si Tower le refuse (configuration révoquée) ou le déconnecte depuis la page Appareils, il s'arrête avec le code 1
      au lieu de réessayer.
    </li>
  </ul>

  <h3>Le lancer</h3>
  <p>
    La page <strong>Appareils</strong>, onglet <em>Connecter un appareil</em>, crée une <strong>configuration</strong>. Elle
    se télécharge de deux façons : un fichier <code>config.json</code> à passer au connecteur, ou un
    <strong>client préconfiguré</strong> qui l'embarque et se lance sans argument. Pour l'installer depuis le terminal de
    l'appareil, <em>Télécharger depuis un terminal</em> donne une commande <code>curl</code> : le lien ne sert qu'une fois et
    expire au bout de 15 minutes.
  </p>
  <p>
    Le secret n'est montré qu'à ce moment-là. Pour retélécharger une configuration, <strong>Nouveau secret</strong> en génère
    un autre : l'ancien fichier ne se connecte plus, et les appareils qui l'utilisaient sont déconnectés.
  </p>
  <CodeBlock :code="run" language="bash" />
  <table>
    <thead><tr><th>Option</th><th>Effet</th></tr></thead>
    <tbody>
      <tr><td><code>--handler "&lt;commande&gt;"</code></td><td>une seule commande, lancée pour chaque événement</td></tr>
      <tr><td><code>--handler-file &lt;fichier&gt;</code></td><td>les handlers lus dans ce fichier JSON plutôt que dans la configuration</td></tr>
      <tr><td><code>--handler-timeout &lt;s&gt;</code></td><td>arrête une commande qui dure plus longtemps (60 par défaut)</td></tr>
      <tr><td><code>--max-jobs &lt;n&gt;</code></td><td>nombre d'événements traités en même temps (4 par défaut)</td></tr>
      <tr><td><code>--no-color</code></td><td>sortie sans couleur, pour les journaux système (aussi avec <code>NO_COLOR</code>)</td></tr>
    </tbody>
  </table>
  <p>
    Pour le relancer à chaque session ou au démarrage, voir
    <a href="#" @click.prevent="$emit('open', 'linux-service')">Service Linux</a> et
    <a href="#" @click.prevent="$emit('open', 'linux-server')">Serveur sans écran</a>.
  </p>

  <h3>Choisir les événements reçus</h3>
  <p>
    Une configuration n'envoie à ses appareils que les événements qu'elle <strong>écoute</strong> : 20 motifs au plus, réglés
    dans la page Appareils, <code>*</code> à la création. <code>*</code> correspond à n'importe quelle suite de caractères,
    points compris : <code>task.*</code>, <code>pr.pipeline.*</code>, <code>jira.issue.status_changed</code>. Une liste vide
    ne reçoit rien. Ce filtre s'applique côté Tower : un événement non écouté n'apparaît même pas dans la console du
    connecteur.
  </p>

  <h3>Les handlers</h3>
  <p>
    Les handlers associent un motif d'événement à une commande, ou à une liste de commandes. Ils se placent dans la section
    <code>handlers</code> de <code>config.json</code>, ou dans un fichier à part passé avec <code>--handler-file</code>, qui
    contient alors directement l'objet des motifs :
  </p>
  <CodeBlock :code="config" language="json" filename="config.json" />
  <p>
    Plutôt que d'écrire ce JSON à la main, tu peux le construire dans Tower : <strong>Configurer les handlers</strong> à la
    création d'une configuration, ou <strong>Modifier</strong> à côté de ses handlers ensuite. Un formulaire par handler
    donne son motif, ses commandes, sa réponse et ses événements suivants, et vérifie tout ce que le connecteur refuserait.
    Tower les écrit dans le fichier téléchargé : il ne les envoie jamais à un appareil. Les enregistrer donne donc un
    nouveau secret à la configuration, et il faut remplacer le fichier sur chaque appareil.
  </p>
  <ul>
    <li>
      Les motifs suivent la même syntaxe que l'écoute. Un événement lance les commandes de <strong>tous</strong> les motifs
      qui lui correspondent, dans l'ordre du fichier.
    </li>
    <li>
      Une liste s'arrête à sa première commande en échec, comme <code>&amp;&amp;</code>. Un motif en échec n'empêche pas les
      motifs suivants de s'exécuter.
    </li>
    <li>
      Le fichier est relu dès qu'il change, sans relancer le connecteur ; s'il est invalide, la version précédente est
      gardée. La configuration embarquée d'un client préconfiguré ne peut pas changer : utilise
      <code>--handler-file</code> pour la compléter.
    </li>
    <li>
      Une commande dont le premier mot est un fichier <code>.py</code> ou <code>.pyz</code> est lancée avec le Python du
      connecteur. <code>~</code> est remplacé par ton dossier personnel.
    </li>
    <li>Sans aucun handler, le connecteur accuse seulement réception des événements.</li>
  </ul>

  <h3>Variables dans les commandes</h3>
  <table>
    <thead><tr><th>Variable</th><th>Valeur</th></tr></thead>
    <tbody>
      <tr><td><code>{event}</code></td><td>le type de l'événement</td></tr>
      <tr><td><code>{id}</code></td><td>la ligne d'historique de l'événement</td></tr>
      <tr><td><code>{delivery}</code></td><td>la livraison à cet appareil</td></tr>
      <tr><td><code>{workspace}</code></td><td>l'identifiant du workspace</td></tr>
      <tr><td><code>{payload.champ}</code></td><td>un champ du payload, champs imbriqués avec des points (<code>{payload.author.name}</code>) ; un objet ou une liste est écrit en JSON</td></tr>
    </tbody>
  </table>
  <p>
    Une variable inconnue reste écrite telle quelle. Une valeur remplace le texte à l'intérieur d'un argument, après le
    découpage de la commande : elle n'ajoute jamais d'argument, même si elle contient des espaces.
  </p>

  <h3>Sans shell</h3>
  <p>
    Les commandes sont lancées <strong>directement</strong>, sans shell : le payload ne peut jamais devenir une commande ou
    une option. En contrepartie, <code>|</code>, <code>&amp;&amp;</code>, <code>&gt;</code>, <code>$VAR</code> ou
    <code>*</code> n'ont pas de sens particulier. Pour en avoir besoin, appelle un shell toi-même, sans y insérer de
    variable <code>{payload…}</code>, ou écris un script :
  </p>
  <CodeBlock :code="shell" language="json" />

  <h3>Ce que reçoit la commande</h3>
  <p>Le message complet, en JSON, sur son <strong>entrée standard</strong> :</p>
  <CodeBlock :code="envelope" language="json" />
  <p>
    Et dans son environnement : <code>TOWER_EVENT</code>, <code>TOWER_EVENT_ID</code>, <code>TOWER_DELIVERY</code> et
    <code>TOWER_WORKSPACE</code>. Le contenu de chaque événement est décrit dans
    <a href="#" @click.prevent="$emit('open', 'events')">Événements</a>.
  </p>

  <h3>Ce qui revient à Tower</h3>
  <p>
    Dans l'Historique, sous l'événement, chaque appareil affiche le statut de sa livraison : <em>Reçu</em> sans commande,
    <em>En cours</em>, puis <em>Réussi</em> ou <em>Échec</em>, avec le code de sortie et la sortie de la commande (la
    sortie standard, ou les erreurs si elle est vide, 64 Kio au plus). Une commande qui dépasse le délai est arrêtée et
    compte comme un échec. Les réponses envoyées pendant une coupure partent dès que la connexion revient.
  </p>

  <h3>Choisir la réponse et enchaîner un événement</h3>
  <p>
    À la place d'une commande ou d'une liste, un motif accepte un objet : <code>run</code> reçoit la commande ou la liste,
    comme d'habitude, <code>response</code> décrit la réponse envoyée à Tower, et <code>next</code> un événement à lancer
    ensuite. Les deux sont facultatifs.
  </p>
  <CodeBlock :code="chained" language="json" />
  <ul>
    <li>
      <code>response</code> peut être n'importe quelle valeur JSON : chaque texte qu'elle contient est complété avec les
      variables des commandes, plus <code>{stdout}</code>, <code>{stderr}</code>, <code>{exit_code}</code> et
      <code>{status}</code> (<code>succeeded</code> ou <code>failed</code>). Elle remplace les sorties des commandes de ce
      motif ; un objet ou une liste part en JSON. Plusieurs motifs : leurs réponses sont mises bout à bout.
    </li>
    <li>
      Un texte qui n'est qu'<strong>une seule</strong> variable garde le type de la valeur : <code>"{payload.count}"</code>
      donne le nombre <code>3</code>, et <code>"{exit_code}"</code> un entier.
    </li>
    <li>
      <code>next</code> est un événement <code>{"type": …, "payload": …}</code>, ou une liste d'événements (10 au plus par
      réponse), complétés de la même façon. Il ne part que si le <code>run</code> de ce motif a <strong>réussi</strong>.
    </li>
    <li>
      Tower le lance comme un événement ordinaire : il passe par l'Historique (« par &lt;appareil&gt;, en réponse à #… »),
      les appareils qui l'écoutent et les règles de notification. Il est refusé s'il a le même type que l'événement
      auquel il répond ; une boucle plus longue reste ta responsabilité.
    </li>
    <li>
      Une clé inconnue dans l'objet (<code>reponse</code> par exemple) rend le fichier invalide : la version précédente
      est gardée et le connecteur l'affiche. Il faut un connecteur à jour, téléchargé à nouveau depuis la modale
      Appareils.
    </li>
    <li>
      L'administrateur du serveur peut couper l'enchaînement dans <code>cube.php</code> :
      <code>new ConnectorConfiguration(allowNextEvents: false)</code>. Les <code>next</code> sont alors ignorés.
    </li>
  </ul>
</template>
