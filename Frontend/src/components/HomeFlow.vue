<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import Message from 'primevue/message'
import {
  getBitbucketIntegration,
  getJiraIntegration,
  listNotificationRules,
  listWorkspaceApiTokens,
  listScheduledEvents,
  listWebhooks,
  type ListNotificationRulesResponse,
  type ListScheduledEventsResponse,
  type ListWorkspaceApiTokensResponse,
  type ListWebhooksResponse,
} from '../client'
import FlowChip, { type ChipLed } from './FlowChip.vue'
import { useDeviceSocketStore, type WorkspaceSignal } from '../stores/deviceSocket'
import { usePushStore } from '../stores/push'
import { useWorkspaceStore } from '../stores/workspace'
import { CHIP_HEIGHT, CHIP_WIDTH, layoutFlow, matchesPattern, pathOf, type FlowChip as Chip, type FlowGroup, type PlacedChip } from '../utils/flowLayout'
import { isSent } from '../utils/historyLine'

// Above this many chips, animating every event costs more than it shows
const LIVE_MAX_CHIPS = 12
const GLOW_MS = 1000
// How long a device keeps the color of its last delivery
const LED_HOLD_MS = 4000

type Glow = { id: number; d: string; tone: 'active' | 'sending' | 'failure'; delay: number }

const { t } = useI18n()
const router = useRouter()
const workspace = useWorkspaceStore()
const socket = useDeviceSocketStore()
const push = usePushStore()

const webhooks = ref<ListWebhooksResponse>([])
const schedules = ref<ListScheduledEventsResponse>([])
const rules = ref<ListNotificationRulesResponse>([])
const agents = ref<ListWorkspaceApiTokensResponse>([])
const jiraSite = ref<string | null>(null)
const bitbucketWorkspace = ref<string | null>(null)

const agendaReminder = computed(() => (workspace.hasSection('agenda') ? (workspace.current?.agenda_reminder_minutes ?? null) : null))

const glows = ref<Glow[]>([])
const deviceLeds = ref<Record<string, ChipLed>>({})
let nextGlow = 0
const ledTimers = new Map<string, ReturnType<typeof setTimeout>>()

async function load() {
  const [webhookList, scheduleList, ruleList, jira, bitbucket, agentList] = await Promise.all([
    workspace.hasSection('webhooks') ? listWebhooks() : null,
    workspace.hasSection('automation') ? listScheduledEvents() : null,
    push.configured ? listNotificationRules() : null,
    workspace.hasSection('jira') ? getJiraIntegration() : null,
    workspace.hasSection('merge_requests') ? getBitbucketIntegration() : null,
    workspace.current?.mcp_access !== 'blocked' ? listWorkspaceApiTokens() : null,
  ])
  webhooks.value = webhookList?.data ?? []
  schedules.value = scheduleList?.data ?? []
  rules.value = ruleList?.data ?? []
  jiraSite.value = jira?.data?.site ?? null
  bitbucketWorkspace.value = bitbucket?.data?.workspace ?? null
  agents.value = agentList?.data ?? []
}

watch(() => [workspace.current?.id, workspace.current?.sections, workspace.current?.mcp_access, push.configured], ([id]) => { if (id) void load() }, { immediate: true })

const sourceGroups = computed<FlowGroup[]>(() => {
  const integrations: Chip[] = [
    ...(jiraSite.value ? [{ key: 'jira', kind: 'jira' as const, label: 'JIRA', detail: jiraSite.value, enabled: true }] : []),
    ...(bitbucketWorkspace.value ? [{ key: 'bitbucket', kind: 'bitbucket' as const, label: 'BITBUCKET', detail: bitbucketWorkspace.value, enabled: true }] : []),
  ]
  const groupsOfSources: FlowGroup[] = [
    {
      key: 'interface',
      label: t('home.flow.interfaceGroup'),
      empty: '',
      chips: [{ key: 'interface', kind: 'interface', label: t('home.flow.members'), detail: t('home.flow.membersDetail'), enabled: true }],
    },
    {
      key: 'agents',
      label: t('home.flow.agentsGroup'),
      empty: '',
      chips: agents.value.map((agent) => ({ key: `agent:${agent.id}`, kind: 'agent', label: agent.name, detail: t(`settings.agents.scope.${agent.scope}`), enabled: true })),
    },
    { key: 'integrations', label: t('home.flow.integrationsGroup'), empty: '', chips: integrations },
    {
      key: 'webhooks',
      label: t('home.flow.webhooksGroup'),
      empty: '',
      chips: webhooks.value.map((webhook) => ({ key: `webhook:${webhook.id}`, kind: 'webhook', label: webhook.name, detail: webhook.events.filter((event): event is string => typeof event === 'string').join(' '), enabled: webhook.enabled })),
    },
    {
      key: 'schedules',
      label: t('home.flow.schedulesGroup'),
      empty: '',
      // A one-time schedule is not part of the wiring : the home lists it among the upcoming ones
      chips: schedules.value.filter((schedule) => schedule.run_at === null).map((schedule) => ({ key: `schedule:${schedule.id}`, kind: 'schedule', label: schedule.name, detail: schedule.type, enabled: schedule.enabled })),
    },
    {
      key: 'agenda',
      label: t('home.flow.agendaGroup'),
      empty: '',
      chips: agendaReminder.value === null
        ? []
        : [{ key: 'agenda', kind: 'agenda', label: t('home.flow.agenda'), detail: t('home.flow.agendaDetail', { minutes: agendaReminder.value }), enabled: true }],
    },
  ]

  // A source family the workspace does not use takes no room
  return groupsOfSources.filter(({ chips }) => chips.length)
})

const outputGroups = computed<FlowGroup[]>(() => [
  {
    key: 'websocket',
    label: t('home.flow.websocket'),
    empty: t('home.flow.noDevice'),
    chips: socket.devices.map((device) => ({
      key: `device:${device.uuid}`,
      kind: 'device',
      label: device.hostname ?? t('devices.unknownHost'),
      detail: device.access_key,
      enabled: true,
    })),
  },
  ...(push.configured
    ? [{
        key: 'push',
        label: t('home.flow.push'),
        empty: t('home.flow.noRule'),
        chips: rules.value.map((rule) => ({ key: `rule:${rule.id}`, kind: 'rule' as const, label: rule.name, detail: rule.listen.join(' '), enabled: rule.enabled })),
      }]
    : []),
])

const layout = computed(() => layoutFlow(sourceGroups.value, outputGroups.value))
const chipCount = computed(() => layout.value.sources.length + layout.value.outputs.length)
const tooMany = computed(() => chipCount.value > LIVE_MAX_CHIPS)

const EXPANDED_KEY = 'tower.home.flowExpanded'
const expanded = ref(readExpanded())

function readExpanded(): boolean {
  try {
    return localStorage.getItem(EXPANDED_KEY) !== '0'
  } catch {
    return true
  }
}

function toggle() {
  expanded.value = !expanded.value
  try {
    localStorage.setItem(EXPANDED_KEY, expanded.value ? '1' : '0')
  } catch {
    // Private browsing or blocked storage : the choice only lasts for this page
  }
}

// Folded, the circuit is not drawn : nothing to animate
const live = computed(() => expanded.value && !tooMany.value && socket.status === 'connected')

const chipsByKey = computed(() => new Map([...layout.value.sources, ...layout.value.outputs].map((chip) => [chip.key, chip])))

function ledOf(chip: PlacedChip): ChipLed {
  if (!chip.enabled) return 'off'
  return deviceLeds.value[chip.key] ?? 'idle'
}

const LINKS: Partial<Record<Chip['kind'], RouteLocationRaw>> = {
  jira: { name: 'jira' },
  bitbucket: { name: 'merge-requests' },
  webhook: { name: 'webhooks' },
  schedule: { name: 'automation', query: { tab: 'schedules' } },
  rule: { name: 'automation', query: { tab: 'notifications' } },
  agenda: { name: 'agenda' },
  agent: { name: 'settings', query: { tab: 'agents' } },
}

function open(chip: PlacedChip) {
  const link = LINKS[chip.kind]
  if (link) void router.push(link)
}

function glow(trace: PlacedChip['trace'], tone: Glow['tone'], delay = 0) {
  const id = nextGlow++
  glows.value = [...glows.value, { id, d: pathOf(trace), tone, delay }]
}

function removeGlow(id: number) {
  glows.value = glows.value.filter((item) => item.id !== id)
}

function sourceOf(signal: Extract<WorkspaceSignal, { type: 'history.created' }>): string {
  if (signal.origin === 'webhook') return `webhook:${signal.source}`
  if (signal.origin === 'schedule') return `schedule:${signal.source}`
  if (signal.origin === 'agenda') return 'agenda'
  if (signal.origin === 'integration') return signal.history_type.startsWith('jira.') ? 'jira' : 'bitbucket'
  if (signal.origin === 'device') return `device:${signal.device}`
  // Its call already lit the chip of the agent, through `mcp.called`
  if (signal.origin === 'mcp') return ''
  return 'interface'
}

function setLed(key: string, led: ChipLed) {
  deviceLeds.value = { ...deviceLeds.value, [key]: led }
  clearTimeout(ledTimers.get(key))
  if (led === 'running' || led === 'pending') return

  ledTimers.set(key, setTimeout(() => {
    const { [key]: _, ...others } = deviceLeds.value
    deviceLeds.value = others
  }, LED_HOLD_MS))
}

// Blue while the event goes out, green once answered, red on a failure ; `running` only blinks the LED
const DELIVERY_GLOWS: Record<string, Glow['tone']> = { pending: 'sending', succeeded: 'active', received: 'active', failed: 'failure' }
const DELIVERY_LEDS: Record<string, ChipLed> = { pending: 'pending', running: 'running', succeeded: 'succeeded', received: 'succeeded', failed: 'failed' }

function animate(signal: WorkspaceSignal) {
  if (signal.type === 'webhook.refused') {
    const chip = chipsByKey.value.get(`webhook:${signal.webhook}`)
    if (chip) glow(chip.trace, 'failure')
    return
  }

  if (signal.type === 'mcp.called') {
    const chip = chipsByKey.value.get(`agent:${signal.token}`)
    if (chip) glow(chip.trace, signal.ok ? 'active' : 'failure')
    return
  }

  if (signal.type === 'delivery.updated') {
    const chip = chipsByKey.value.get(`device:${signal.device}`)
    if (!chip) return
    setLed(chip.key, DELIVERY_LEDS[signal.status] ?? 'idle')
    const tone = DELIVERY_GLOWS[signal.status]
    if (tone) glow(chip.trace, tone)
    return
  }

  if (!isSent(signal.history_type)) return

  const source = chipsByKey.value.get(sourceOf(signal))
  if (source) glow(source.trace, 'active')

  const event = signal.event ?? signal.history_type
  for (const rule of rules.value) {
    const chip = chipsByKey.value.get(`rule:${rule.id}`)
    if (chip && rule.enabled && rule.listen.some((pattern) => matchesPattern(pattern, event))) glow(chip.trace, 'active', GLOW_MS)
  }
}

// A webhook, a schedule or a token created, changed or deleted adds, changes or removes its chip
let reloadTimer: ReturnType<typeof setTimeout> | undefined
function reloadSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => void load(), 300)
}

function onSignal(signal: WorkspaceSignal) {
  if (signal.type === 'history.created' && /^(webhook|schedule|token)\./.test(signal.history_type)) reloadSoon()
  if (live.value) animate(signal)
}

const stopListening = socket.onSignal(onSignal)
onBeforeUnmount(() => {
  stopListening()
  clearTimeout(reloadTimer)
  ledTimers.forEach(clearTimeout)
})
</script>

<template>
  <section class="panel flow">
    <header class="panel-header" :class="{ folded: !expanded }">
      <button type="button" class="panel-toggle" :aria-expanded="expanded" @click="toggle">
        <i :class="['pi', expanded ? 'pi-chevron-down' : 'pi-chevron-right']" aria-hidden="true" />
        <h2>{{ t('home.flow.title') }}</h2>
      </button>
      <span v-if="expanded" class="status" :class="{ on: live }">
        <span class="status-dot" aria-hidden="true" />
        {{ live ? t('home.flow.live') : t('home.flow.paused') }}
      </span>
    </header>

    <template v-if="expanded">
      <Message v-if="tooMany" class="notice" severity="info" :closable="false">{{ t('home.flow.tooMany', { max: LIVE_MAX_CHIPS }) }}</Message>
      <Message v-else-if="socket.status !== 'connected'" class="notice" severity="warn" :closable="false">{{ t('home.flow.offline') }}</Message>

      <svg class="board" :viewBox="`0 0 ${layout.width} ${layout.height}`" role="img" :aria-label="t('home.flow.title')">
        <defs>
          <pattern id="flow-grid" width="20" height="20" patternUnits="userSpaceOnUse">
            <circle cx="1" cy="1" r="1" class="grid-dot" />
          </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#flow-grid)" />

        <g class="traces">
          <path v-for="chip in [...layout.sources, ...layout.outputs]" :key="`trace-${chip.key}`" :d="pathOf(chip.trace)" class="trace" :class="{ off: !chip.enabled }" />
          <template v-for="chip in [...layout.sources, ...layout.outputs]" :key="`via-${chip.key}`">
            <circle class="via" :cx="chip.trace[0]!.x" :cy="chip.trace[0]!.y" r="3" />
            <circle class="via" :cx="chip.trace.at(-1)!.x" :cy="chip.trace.at(-1)!.y" r="3" />
          </template>
        </g>

        <path
          v-for="item in glows"
          :key="item.id"
          :d="item.d"
          class="glow"
          :class="`glow-${item.tone}`"
          :style="{ animationDuration: `${GLOW_MS}ms`, animationDelay: `${item.delay}ms` }"
          @animationend="removeGlow(item.id)"
        />

        <text v-for="header in layout.headers" :key="header.label" class="header" :x="header.x" :y="header.y">{{ header.label }}</text>
        <g v-for="placeholder in layout.placeholders" :key="placeholder.label">
          <rect class="placeholder" :x="placeholder.x" :y="placeholder.y" :width="CHIP_WIDTH" :height="CHIP_HEIGHT" rx="4" />
          <text class="placeholder-text" :x="placeholder.x + CHIP_WIDTH / 2" :y="placeholder.y + CHIP_HEIGHT / 2 + 4">{{ placeholder.label }}</text>
        </g>

        <FlowChip
          v-for="chip in [...layout.sources, ...layout.outputs]"
          :key="chip.key"
          :x="chip.x"
          :y="chip.y"
          :width="CHIP_WIDTH"
          :height="CHIP_HEIGHT"
          :label="chip.label"
          :detail="chip.detail"
          :led="ledOf(chip)"
          :clickable="chip.kind in LINKS"
          @click="open(chip)"
          @keydown.enter="open(chip)"
        />

        <FlowChip
          :x="layout.tower.x"
          :y="layout.tower.y"
          :width="layout.tower.width"
          :height="layout.tower.height"
          label="Tower"
          :detail="workspace.current?.name ?? ''"
          :led="live ? 'idle' : 'off'"
          logo
        />
      </svg>
    </template>
  </section>
</template>

<style scoped>
.panel {
  min-width: 0;
  padding: var(--panel-padding);
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.panel-header.folded {
  margin-bottom: 0;
}

.panel-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0;
  color: inherit;
  background: none;
  border: 0;
  font: inherit;
  cursor: pointer;
}

.panel-toggle:focus-visible {
  outline: 2px solid var(--p-primary-color);
  border-radius: var(--p-content-border-radius);
}

.panel-toggle .pi {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.panel-toggle h2 {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
}

.status {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
}

.status-dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: var(--p-surface-400);
}

.status.on .status-dot {
  background: var(--p-green-500);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--p-green-500) 25%, transparent);
}

.notice {
  margin-bottom: 0.75rem;
}

.board {
  display: block;
  width: 100%;
  height: auto;
  border-radius: var(--p-content-border-radius);
  background: light-dark(var(--p-surface-50), color-mix(in srgb, var(--p-surface-950) 70%, var(--p-surface-900)));
}

.grid-dot {
  fill: light-dark(var(--p-surface-300), var(--p-surface-800));
}

.trace {
  fill: none;
  stroke: light-dark(var(--p-surface-400), var(--p-surface-600));
  stroke-width: 2;
  stroke-linejoin: round;
}

.trace.off {
  stroke-dasharray: 4 4;
  opacity: 0.6;
}

.via {
  fill: light-dark(var(--p-surface-0), var(--p-surface-900));
  stroke: light-dark(var(--p-surface-400), var(--p-surface-600));
  stroke-width: 2;
}

.glow {
  fill: none;
  stroke-width: 2.5;
  stroke-linejoin: round;
  opacity: 0;
  animation-name: glow;
  animation-timing-function: ease-out;
  animation-fill-mode: both;
}

.glow-active {
  stroke: var(--p-green-500);
  filter: drop-shadow(0 0 3px var(--p-green-500));
}

.glow-sending {
  stroke: var(--p-primary-color);
  filter: drop-shadow(0 0 3px var(--p-primary-color));
}

.glow-failure {
  stroke: var(--p-red-500);
  filter: drop-shadow(0 0 3px var(--p-red-500));
}

@keyframes glow {
  0% {
    opacity: 0;
  }

  15%,
  60% {
    opacity: 1;
  }

  100% {
    opacity: 0;
  }
}

.header {
  fill: var(--p-text-muted-color);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.placeholder {
  fill: none;
  stroke: light-dark(var(--p-surface-300), var(--p-surface-700));
  stroke-dasharray: 5 4;
}

.placeholder-text {
  fill: var(--p-text-muted-color);
  font-size: 12px;
  text-anchor: middle;
}
</style>
