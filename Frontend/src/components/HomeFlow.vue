<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import Message from 'primevue/message'
import {
  getBitbucketIntegration,
  getJiraIntegration,
  listNotificationRules,
  listScheduledEvents,
  listWebhooks,
  type ListNotificationRulesResponse,
  type ListScheduledEventsResponse,
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
const PULSE_MS = 900
// How long a device keeps the color of its last delivery
const LED_HOLD_MS = 4000

type Pulse = { id: number; d: string; tone: 'event' | 'success' | 'failure'; delay: number }

const { t } = useI18n()
const router = useRouter()
const workspace = useWorkspaceStore()
const socket = useDeviceSocketStore()
const push = usePushStore()

const webhooks = ref<ListWebhooksResponse>([])
const schedules = ref<ListScheduledEventsResponse>([])
const rules = ref<ListNotificationRulesResponse>([])
const jiraSite = ref<string | null>(null)
const bitbucketWorkspace = ref<string | null>(null)

const pulses = ref<Pulse[]>([])
const deviceLeds = ref<Record<string, ChipLed>>({})
let nextPulse = 0
const ledTimers = new Map<string, ReturnType<typeof setTimeout>>()

async function load() {
  const [webhookList, scheduleList, ruleList, jira, bitbucket] = await Promise.all([
    workspace.hasSection('webhooks') ? listWebhooks() : null,
    workspace.hasSection('automation') ? listScheduledEvents() : null,
    push.configured ? listNotificationRules() : null,
    workspace.hasSection('jira') ? getJiraIntegration() : null,
    workspace.hasSection('merge_requests') ? getBitbucketIntegration() : null,
  ])
  webhooks.value = webhookList?.data ?? []
  schedules.value = scheduleList?.data ?? []
  rules.value = ruleList?.data ?? []
  jiraSite.value = jira?.data?.site ?? null
  bitbucketWorkspace.value = bitbucket?.data?.workspace ?? null
}

watch(() => [workspace.current?.id, workspace.current?.sections, push.configured], ([id]) => { if (id) void load() }, { immediate: true })

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
      chips: schedules.value.map((schedule) => ({ key: `schedule:${schedule.id}`, kind: 'schedule', label: schedule.name, detail: schedule.type, enabled: schedule.enabled })),
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
const live = computed(() => !tooMany.value && socket.status === 'connected')

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
}

function open(chip: PlacedChip) {
  const link = LINKS[chip.kind]
  if (link) void router.push(link)
}

function emitPulse(trace: PlacedChip['trace'], tone: Pulse['tone'], delay = 0, reverse = false) {
  const id = nextPulse++
  pulses.value = [...pulses.value, { id, d: pathOf(reverse ? [...trace].reverse() : trace), tone, delay }]
}

function removePulse(id: number) {
  pulses.value = pulses.value.filter((pulse) => pulse.id !== id)
}

function sourceOf(signal: Extract<WorkspaceSignal, { type: 'history.created' }>): string {
  if (signal.origin === 'webhook') return `webhook:${signal.source}`
  if (signal.origin === 'schedule') return `schedule:${signal.source}`
  if (signal.origin === 'integration') return signal.history_type.startsWith('jira.') ? 'jira' : 'bitbucket'
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

const DELIVERY_LEDS: Record<string, ChipLed> = { pending: 'pending', running: 'running', succeeded: 'succeeded', received: 'succeeded', failed: 'failed' }

function animate(signal: WorkspaceSignal) {
  if (signal.type === 'webhook.refused') {
    const chip = chipsByKey.value.get(`webhook:${signal.webhook}`)
    if (chip) emitPulse(chip.trace, 'failure')
    return
  }

  if (signal.type === 'delivery.updated') {
    const chip = chipsByKey.value.get(`device:${signal.device}`)
    if (!chip) return
    setLed(chip.key, DELIVERY_LEDS[signal.status] ?? 'idle')
    if (signal.status === 'pending') emitPulse(chip.trace, 'event')
    if (signal.status === 'succeeded' || signal.status === 'received') emitPulse(chip.trace, 'success', 0, true)
    if (signal.status === 'failed') emitPulse(chip.trace, 'failure', 0, true)
    return
  }

  if (!isSent(signal.history_type)) return

  const source = chipsByKey.value.get(sourceOf(signal))
  if (source) emitPulse(source.trace, 'event')

  const event = signal.event ?? signal.history_type
  for (const rule of rules.value) {
    const chip = chipsByKey.value.get(`rule:${rule.id}`)
    if (chip && rule.enabled && rule.listen.some((pattern) => matchesPattern(pattern, event))) emitPulse(chip.trace, 'event', PULSE_MS)
  }
}

// A webhook or a schedule created, changed or deleted adds, changes or removes its chip
let reloadTimer: ReturnType<typeof setTimeout> | undefined
function reloadSoon() {
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => void load(), 300)
}

function onSignal(signal: WorkspaceSignal) {
  if (signal.type === 'history.created' && /^(webhook|schedule)\./.test(signal.history_type)) reloadSoon()
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
    <header class="panel-header">
      <h2>{{ t('home.flow.title') }}</h2>
      <span class="status" :class="{ on: live }">
        <span class="status-dot" aria-hidden="true" />
        {{ live ? t('home.flow.live') : t('home.flow.paused') }}
      </span>
    </header>

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
        v-for="pulse in pulses"
        :key="pulse.id"
        :d="pulse.d"
        class="pulse"
        :class="`pulse-${pulse.tone}`"
        pathLength="100"
        :style="{ animationDuration: `${PULSE_MS}ms`, animationDelay: `${pulse.delay}ms` }"
        @animationend="removePulse(pulse.id)"
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
  </section>
</template>

<style scoped>
.panel {
  min-width: 0;
  padding: 1.25rem;
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

.panel-header h2 {
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

.pulse {
  fill: none;
  stroke-width: 3;
  stroke-linecap: round;
  stroke-dasharray: 6 200;
  animation-name: travel;
  animation-timing-function: ease-in-out;
  animation-fill-mode: both;
}

.pulse-event {
  stroke: var(--p-primary-color);
  filter: drop-shadow(0 0 4px var(--p-primary-color));
}

.pulse-success {
  stroke: var(--p-green-400);
  filter: drop-shadow(0 0 4px var(--p-green-400));
}

.pulse-failure {
  stroke: var(--p-red-500);
  filter: drop-shadow(0 0 4px var(--p-red-500));
}

@keyframes travel {
  from {
    stroke-dashoffset: 6;
  }

  to {
    stroke-dashoffset: -100;
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
