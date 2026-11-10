<script setup lang="ts">
import CodeBlock from '../../../components/CodeBlock.vue'

const copy = `
# From your computer, after downloading the connector and creating its configuration in Tower
ssh my-server 'mkdir -p ~/tower && chmod 700 ~/tower'
scp ~/Downloads/tower-connector.pyz my-server:tower/
scp ~/Downloads/tower-config.json my-server:tower/config.json
ssh my-server 'chmod 600 ~/tower/config.json'
`

const handlers = `
{
  "system.deploy": "make -C /home/me/apps/my-app deploy",
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
# Crash or kill: restarted. Disconnected from Tower or configuration refused (code 1): stays stopped
Restart=on-abnormal
RestartSec=5
# A service does not read your .bashrc: your handlers only find what is in this PATH
Environment=PATH=%h/.local/bin:/usr/local/bin:/usr/bin:/bin
Environment=NO_COLOR=1

[Install]
WantedBy=default.target
`

const enable = `
# Your user services start at boot and outlive your sessions
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
# The connector may reboot this machine, and nothing else
me ALL=(root) NOPASSWD: /usr/bin/systemctl reboot
`

const bus = `
# "Failed to connect to bus": the session did not start your user bus
machinectl shell me@
`
</script>

<template>
  <h2>The connector on a headless server</h2>
  <p class="lead">
    On a server, the connector must start at boot and keep running when you close your SSH session. It is a systemd user
    service, detached from the sessions.
  </p>

  <h3>Good to know</h3>
  <ul>
    <li>
      By default, systemd stops your user services when your last session closes: the connector would go down with each
      SSH logout. <strong>Lingering</strong> (<code>loginctl enable-linger</code>) detaches them from the sessions: they
      start at boot, even when nobody logs in.
    </li>
    <li>
      There is no graphical session: the service depends on <code>default.target</code>, not on
      <code>graphical-session.target</code>. The basic handler, which opens terminal windows, is of no use here: give the
      connector your own handlers.
    </li>
    <li>
      The connector runs as your user, never as root: its handlers have your rights, and nothing more. For an action that
      needs root, allow that single command (see below).
    </li>
    <li>
      It reconnects on its own when the network or Tower goes down, and only stops when you disconnect it from the Devices
      dialog, or when Tower refuses its configuration (revoked, for instance): it then exits with code <code>1</code>, and
      the service leaves it stopped.
    </li>
  </ul>

  <h3>1. Copy the files</h3>
  <p>
    Downloading the connector needs you to be logged in to Tower: download it from your computer (Devices dialog,
    <em>Connect a device</em> tab), create a configuration for this server, then copy both files. The configuration holds
    the secret: it must be readable by you only.
  </p>
  <CodeBlock :code="copy" language="bash" />
  <p>
    With a preconfigured connector (<code>tower-…-….pyz</code>), copy it alone, and remove
    <code>%h/tower/config.json</code> from the service command.
  </p>

  <h3>2. Write the handlers</h3>
  <p>
    A separate handlers file can change without restarting the connector: it is read again at the next event. Commands run
    without a shell: no <code>cd</code>, <code>&amp;&amp;</code> or <code>|</code>. Use the options of the commands
    themselves (<code>make -C</code>, <code>git -C</code>), or a list of commands, chained like <code>&amp;&amp;</code>.
  </p>
  <CodeBlock :code="handlers" language="json" filename="~/tower/handlers.json" />

  <h3>3. Create the service</h3>
  <CodeBlock :code="unit" language="ini" filename="~/.config/systemd/user/tower-connector.service" />
  <p>
    <code>--handler-timeout</code> stops a command that takes too long: 60 seconds by default, too short for a deployment.
    While a handler runs, a lost connection does not interrupt it: its response is sent on reconnection.
  </p>

  <h3>4. Detach it from the sessions and enable it</h3>
  <CodeBlock :code="enable" language="bash" />

  <h3>5. Check</h3>
  <CodeBlock :code="check" language="bash" />
  <p>
    Close your SSH session, then look in Tower: the server must stay among the connected devices. After a reboot, it must
    come back without you logging in.
  </p>

  <h3>A command that needs root</h3>
  <p>
    Rather than running the connector as root, allow the one command you need without a password, with its exact
    arguments. In the handler, <code>sudo -n</code> fails at once when the rule does not match, instead of waiting for a
    password nobody will type.
  </p>
  <CodeBlock :code="sudoers" language="bash" filename="/etc/sudoers.d/tower-reboot" />
  <p class="note">A reboot stops the connector before it answers: the history keeps that delivery running.</p>

  <h3>Troubleshooting</h3>
  <ul>
    <li>
      <code>systemctl --user</code> answers <em>Failed to connect to bus</em>: you went through <code>sudo su</code>, which
      does not start the user bus. Log in directly over SSH as that user, or:
    </li>
  </ul>
  <CodeBlock :code="bus" language="bash" />
  <ul>
    <li>
      A handler answers <em>not found</em>: the command is not in the <code>PATH</code> of the service. Give its full path,
      or extend <code>Environment=PATH</code>.
    </li>
    <li>
      A handler using <code>git</code> over SSH fails while it works in your terminal: there is no SSH agent in the
      service. Declare the key in <code>~/.ssh/config</code> (<code>IdentityFile</code>), without a passphrase, preferably
      a read-only deploy key.
    </li>
    <li>
      A handler using <code>docker</code> fails with <em>permission denied</em>: your user must be in the
      <code>docker</code> group, then the service restarted.
    </li>
    <li>
      After a disconnection from Tower, the service stayed stopped on purpose:
      <code>systemctl --user start tower-connector</code>.
    </li>
  </ul>
</template>
