<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'

const copy = `
# Depuis ton poste, après avoir téléchargé le connecteur et créé sa configuration dans Tower
ssh mon-serveur 'mkdir -p ~/tower && chmod 700 ~/tower'
scp ~/Téléchargements/tower-connector.pyz mon-serveur:tower/
scp ~/Téléchargements/tower-config.json mon-serveur:tower/config.json
ssh mon-serveur 'chmod 600 ~/tower/config.json'
`

const handlers = `
{
  "system.deploy": "make -C /home/moi/apps/mon-appli deploy",
  "system.reboot": "sudo -n /usr/bin/systemctl reboot",
  "*": "logger -t tower {event}"
}
`

const unit = `
[Unit]
Description=Tower connector
After=network-online.target

[Service]
ExecStart=/usr/bin/python3 %h/tower/tower-connector.pyz %h/tower/config.json --handler-file %h/tower/handlers.json --handler-timeout 1800
# Plantage ou kill : relancé. Déconnexion depuis Tower ou configuration refusée (code 1) : il reste arrêté
Restart=on-abnormal
RestartSec=5
# Un service ne lit pas ton .bashrc : tes handlers ne trouvent que ce qui est dans ce PATH
Environment=PATH=%h/.local/bin:/usr/local/bin:/usr/bin:/bin
Environment=NO_COLOR=1

[Install]
WantedBy=default.target
`

const enable = `
# Tes services utilisateur démarrent au boot et survivent à la fermeture de tes sessions
loginctl enable-linger "$USER"

systemctl --user daemon-reload
systemctl --user enable --now tower-connector
`

const check = `
loginctl show-user "$USER" --property=Linger   # Linger=yes
systemctl --user status tower-connector
journalctl --user -u tower-connector -f
`

const sudoers = `
# sudo visudo -f /etc/sudoers.d/tower-reboot
# Le connecteur peut redémarrer cette machine, et rien d'autre
moi ALL=(root) NOPASSWD: /usr/bin/systemctl reboot
`

const bus = `
# « Failed to connect to bus » : la session n'a pas démarré ton bus utilisateur
machinectl shell moi@
`
</script>

<template>
  <h2>Le connecteur sur un serveur sans écran</h2>
  <p class="lead">
    Sur un serveur, le connecteur doit démarrer au boot et continuer de tourner quand tu fermes ta session SSH. C'est un
    service utilisateur systemd, détaché des sessions.
  </p>

  <h3>Ce qu'il faut savoir</h3>
  <ul>
    <li>
      Sans réglage, systemd arrête tes services utilisateur quand ta dernière session se ferme : le connecteur tomberait à
      chaque déconnexion SSH. Le <strong>lingering</strong> (<code>loginctl enable-linger</code>) les détache des sessions :
      ils démarrent au boot, même si personne ne se connecte.
    </li>
    <li>
      Il n'y a pas de session graphique : le service dépend de <code>default.target</code>, pas de
      <code>graphical-session.target</code>. Le handler de base, qui ouvre des fenêtres de terminal, ne sert à rien ici :
      donne au connecteur tes propres handlers.
    </li>
    <li>
      Le connecteur tourne sous ton utilisateur, jamais en root : ses handlers ont tes droits, et rien de plus. Pour une
      action qui demande root, autorise cette seule commande (voir plus bas).
    </li>
    <li>
      Il se reconnecte seul quand le réseau ou Tower tombe, et ne s'arrête que si tu le déconnectes depuis la page
      Appareils, ou si Tower refuse sa configuration (révoquée par exemple) : il sort alors avec le code <code>1</code>, et
      le service le laisse arrêté.
    </li>
  </ul>

  <h3>1. Copier les fichiers</h3>
  <p>
    Le téléchargement du connecteur demande d'être connecté à Tower : télécharge-le depuis ton poste (page Appareils,
    onglet <em>Connecter un appareil</em>), crée une configuration pour ce serveur, puis copie les deux fichiers. La
    configuration contient le secret : elle ne doit être lisible que par toi.
  </p>
  <CodeBlock :code="copy" language="bash" />
  <p>
    Avec un connecteur préconfiguré (<code>tower-…-….pyz</code>), copie-le seul, et retire
    <code>%h/tower/config.json</code> de la commande du service.
  </p>

  <h3>2. Écrire les handlers</h3>
  <p>
    Un fichier de handlers à part se modifie sans relancer le connecteur : il est relu à l'événement suivant. Les commandes
    sont exécutées sans shell : pas de <code>cd</code>, de <code>&amp;&amp;</code> ni de <code>|</code>. Utilise les
    options des commandes elles-mêmes (<code>make -C</code>, <code>git -C</code>), ou une liste de commandes, qui
    s'enchaînent comme <code>&amp;&amp;</code>.
  </p>
  <CodeBlock :code="handlers" language="json" filename="~/tower/handlers.json" />

  <h3>3. Créer le service</h3>
  <CodeBlock :code="unit" language="ini" filename="~/.config/systemd/user/tower-connector.service" />
  <p>
    <code>--handler-timeout</code> arrête une commande trop longue : 60 secondes par défaut, trop peu pour un déploiement.
    Pendant qu'un handler tourne, une coupure de connexion ne l'interrompt pas : sa réponse part à la reconnexion.
  </p>

  <h3>4. Le détacher des sessions et l'activer</h3>
  <CodeBlock :code="enable" language="bash" />

  <h3>5. Vérifier</h3>
  <CodeBlock :code="check" language="bash" />
  <p>
    Ferme ta session SSH, puis regarde dans Tower : le serveur doit rester dans les appareils connectés. Après un
    redémarrage, il doit revenir sans que tu te connectes.
  </p>

  <h3>Une commande qui demande root</h3>
  <p>
    Plutôt que de lancer le connecteur en root, autorise sans mot de passe la seule commande voulue, avec ses arguments
    exacts. Dans le handler, <code>sudo -n</code> échoue tout de suite si la règle ne correspond pas, au lieu d'attendre un
    mot de passe que personne ne tapera.
  </p>
  <CodeBlock :code="sudoers" language="bash" filename="/etc/sudoers.d/tower-reboot" />
  <p class="note">
    Un redémarrage coupe le connecteur avant qu'il ne réponde : l'historique garde cette livraison en cours.
  </p>

  <h3>En cas de problème</h3>
  <ul>
    <li>
      <code>systemctl --user</code> répond <em>Failed to connect to bus</em> : tu es passé par <code>sudo su</code>, qui ne
      démarre pas le bus utilisateur. Connecte-toi directement en SSH avec cet utilisateur, ou :
    </li>
  </ul>
  <CodeBlock :code="bus" language="bash" />
  <ul>
    <li>
      Un handler répond <em>introuvable</em> : la commande n'est pas dans le <code>PATH</code> du service. Donne son chemin
      complet, ou complète <code>Environment=PATH</code>.
    </li>
    <li>
      Un handler qui utilise <code>git</code> en SSH échoue alors qu'il marche dans ton terminal : il n'y a pas d'agent SSH
      dans le service. Déclare la clé dans <code>~/.ssh/config</code> (<code>IdentityFile</code>), sans passphrase, de
      préférence une clé de déploiement en lecture seule.
    </li>
    <li>
      Un handler qui utilise <code>docker</code> échoue avec <em>permission denied</em> : ton utilisateur doit être dans le
      groupe <code>docker</code>, puis le service relancé.
    </li>
    <li>
      Après une déconnexion depuis Tower, le service est resté arrêté volontairement :
      <code>systemctl --user start tower-connector</code>.
    </li>
  </ul>
</template>
