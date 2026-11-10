<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'
import type { DocPage } from '../pages'

defineEmits<{ open: [page: DocPage] }>()

const unit = `
[Unit]
Description=Tower connector
After=graphical-session.target network-online.target
PartOf=graphical-session.target

[Service]
ExecStart=/usr/bin/python3 %h/tower/tower-connector.pyz %h/tower/config.json --handler %h/tower/tower-handler.pyz
# Plantage ou kill : relancé. Déconnexion depuis Tower ou configuration refusée (code 1) : il reste arrêté
Restart=on-abnormal
RestartSec=5
# Un service ne lit pas ton .bashrc : claude doit être dans ce PATH
Environment=PATH=%h/.local/bin:/usr/local/bin:/usr/bin:/bin
Environment=NO_COLOR=1

[Install]
WantedBy=graphical-session.target
`

const install = `
mkdir -p ~/tower && chmod 700 ~/tower
mv ~/Téléchargements/tower-connector.pyz ~/Téléchargements/tower-handler.pyz ~/tower/
mv ~/Téléchargements/tower-config.json ~/tower/config.json && chmod 600 ~/tower/config.json
`

const enable = `
systemctl --user daemon-reload
systemctl --user enable --now tower-connector
`

const logs = `
systemctl --user status tower-connector
journalctl --user -u tower-connector -f
`

const restart = 'systemctl --user start tower-connector'
</script>

<template>
  <h2>Lancer le connecteur en service sous Linux</h2>
  <p class="lead">
    Un service utilisateur systemd relance le connecteur à chaque ouverture de session, et après un plantage.
  </p>

  <h3>Ce qu'il faut savoir</h3>
  <ul>
    <li>
      Le connecteur se reconnecte déjà tout seul quand le réseau ou Tower tombe. Il ne s'arrête de lui-même que si tu le
      déconnectes depuis la modale Appareils, ou si Tower refuse sa configuration (révoquée par exemple) : il sort alors avec
      le code <code>1</code>, et le service le laisse arrêté. Sinon, le bouton <em>Déconnecter</em> ne servirait à rien.
    </li>
    <li>
      C'est un service <strong>utilisateur</strong>, pas un service système : il tourne dans ta session graphique, ce qui
      permet au handler de base d'ouvrir ses fenêtres de terminal.
    </li>
  </ul>

  <h3>1. Ranger les fichiers</h3>
  <p>
    Un service ne démarre pas dans ton dossier personnel : les chemins doivent être absolus. Ici, tout va dans
    <code>~/tower</code>, et la configuration, qui contient le secret, n'est lisible que par toi.
  </p>
  <CodeBlock :code="install" language="bash" />

  <h3>2. Créer le service</h3>
  <p>Crée ce fichier, en adaptant les chemins si besoin (<code>%h</code> est ton dossier personnel) :</p>
  <CodeBlock :code="unit" language="ini" filename="~/.config/systemd/user/tower-connector.service" />
  <p>
    Sans handler, retire <code>--handler …</code> : le connecteur affichera simplement les événements reçus.
  </p>

  <h3>3. L'activer</h3>
  <CodeBlock :code="enable" language="bash" />
  <p>Il démarre tout de suite, puis à chaque ouverture de session, et s'arrête quand tu la fermes.</p>

  <h3>Suivre ce qu'il fait</h3>
  <p>L'état du service, puis ses logs en direct, avec la sortie des handlers qui échouent :</p>
  <CodeBlock :code="logs" language="bash" />

  <h3>Après une déconnexion depuis Tower</h3>
  <p>Le service est resté arrêté volontairement. Pour le relancer :</p>
  <CodeBlock :code="restart" language="bash" />

  <h3>Sur un serveur sans écran</h3>
  <p>
    Ce service suit ta session graphique. Pour un serveur, où le connecteur doit démarrer au boot et survivre à la fermeture
    de tes sessions SSH, suis la page
    <a href="#" @click.prevent="$emit('open', 'linux-server')">Serveur sans écran</a>.
  </p>
</template>
