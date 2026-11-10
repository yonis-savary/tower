<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'

const unit = `
[Unit]
Description=Tower connector
After=graphical-session.target network-online.target
PartOf=graphical-session.target

[Service]
ExecStart=/usr/bin/python3 %h/tower/tower-connector.pyz %h/tower/config.json --handler %h/tower/tower-handler.pyz
# Crash or kill: restarted. Disconnected from Tower or configuration refused (code 1): it stays stopped
Restart=on-abnormal
RestartSec=5
# A service does not read your .bashrc: claude must be in this PATH
Environment=PATH=%h/.local/bin:/usr/local/bin:/usr/bin:/bin
Environment=NO_COLOR=1

[Install]
WantedBy=graphical-session.target
`

const install = `
mkdir -p ~/tower && chmod 700 ~/tower
mv ~/Downloads/tower-connector.pyz ~/Downloads/tower-handler.pyz ~/tower/
mv ~/Downloads/tower-config.json ~/tower/config.json && chmod 600 ~/tower/config.json
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

const headless = `
# In the service file, replace graphical-session.target with default.target, then:
loginctl enable-linger "$USER"
systemctl --user daemon-reload
systemctl --user reenable tower-connector
`
</script>

<template>
  <h2>Run the connector as a Linux service</h2>
  <p class="lead">A systemd user service restarts the connector with each session, and after a crash.</p>

  <h3>Good to know</h3>
  <ul>
    <li>
      The connector already reconnects on its own when the network or Tower goes down. It only stops by itself when you
      disconnect it from the Devices dialog, or when Tower refuses its configuration (revoked, for instance): it then exits
      with code <code>1</code>, and the service leaves it stopped. Otherwise, the <em>Disconnect</em> button would be useless.
    </li>
    <li>
      It is a <strong>user</strong> service, not a system one: it runs in your graphical session, which lets the basic
      handler open its terminal windows.
    </li>
  </ul>

  <h3>1. Put the files in place</h3>
  <p>
    A service does not start in your home directory: paths must be absolute. Here everything goes into
    <code>~/tower</code>, and the configuration, which holds the secret, is readable by you only.
  </p>
  <CodeBlock :code="install" language="bash" />

  <h3>2. Create the service</h3>
  <p>Create this file, adapting the paths if needed (<code>%h</code> is your home directory):</p>
  <CodeBlock :code="unit" language="ini" filename="~/.config/systemd/user/tower-connector.service" />
  <p>Without a handler, remove <code>--handler …</code>: the connector will simply print the events it receives.</p>

  <h3>3. Enable it</h3>
  <CodeBlock :code="enable" language="bash" />
  <p>It starts right away, then with each session, and stops when you close it.</p>

  <h3>Follow what it does</h3>
  <p>The state of the service, then its live logs, with the output of the handlers that fail:</p>
  <CodeBlock :code="logs" language="bash" />

  <h3>After a disconnection from Tower</h3>
  <p>The service stayed stopped on purpose. To start it again:</p>
  <CodeBlock :code="restart" language="bash" />

  <h3>On a machine without a screen</h3>
  <p>For a server that opens no windows, the service can start at boot, without anyone logging in:</p>
  <CodeBlock :code="headless" language="bash" />
  <p class="note">
    The basic handler needs a graphical session to open its windows: on a machine without a screen, use your own handler,
    or none.
  </p>
</template>
