<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { listAgendaItems, listScheduledEvents, type ListAgendaItemsResponse, type ListScheduledEventsResponse } from '../client'
import { useAuthStore } from '../stores/auth'
import { useDeviceSocketStore, type WorkspaceSignal } from '../stores/deviceSocket'
import { useWorkspaceStore } from '../stores/workspace'
import { addDays } from '../utils/agenda'

const TICK_MS = 30_000
const AGENDA_DAYS = 7
const MAX_ENTRIES = 8

type Entry = {
  key: string
  title: string
  detail: string | null
  icon: string
  color: string
  at: Date
  until: Date | null
  allDay: boolean
  to: RouteLocationRaw
}

const { t, d, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const workspace = useWorkspaceStore()
const socket = useDeviceSocketStore()

const hasAgenda = computed(() => workspace.hasSection('agenda'))
const hasSchedules = computed(() => workspace.hasSection('automation'))

const items = ref<ListAgendaItemsResponse>([])
const schedules = ref<ListScheduledEventsResponse>([])
// null until the first answer ; hidden while nothing is to come
const filled = defineModel<boolean | null>('filled', { default: null })
const now = ref(Date.now())
const ticker = setInterval(() => (now.value = Date.now()), TICK_MS)

const workspaceColor = computed(() => workspace.current?.accent_color ?? 'var(--p-primary-color)')

const entries = computed<Entry[]>(() => [
  ...items.value
    .filter((item) => new Date(item.ends_at).getTime() > now.value)
    .map((item) => ({
      key: `item:${item.id}`,
      title: item.title,
      detail: item.participants.length ? item.participants.map(({ email }) => email).join(', ') : null,
      icon: 'pi pi-calendar',
      color: item.color ?? workspaceColor.value,
      // An all-day item keeps its days whatever the timezone of the browser
      at: item.all_day ? new Date(`${item.starts_at.slice(0, 10)}T00:00:00`) : new Date(item.starts_at),
      until: item.all_day ? null : new Date(item.ends_at),
      allDay: item.all_day,
      to: { name: 'agenda' },
    })),
  ...schedules.value
    .filter((schedule) => schedule.run_at !== null && schedule.enabled && new Date(schedule.run_at).getTime() > now.value)
    .map((schedule) => ({
      key: `schedule:${schedule.id}`,
      title: schedule.name,
      detail: schedule.type,
      icon: 'pi pi-calendar-clock',
      color: 'var(--p-primary-color)',
      at: new Date(schedule.run_at!),
      until: null,
      allDay: false,
      to: { name: 'automation', query: { tab: 'schedules' } },
    })),
]
  .sort((a, b) => a.at.getTime() - b.at.getTime())
  .slice(0, MAX_ENTRIES))

watch(entries, (list) => { if (filled.value !== null) filled.value = list.length > 0 })

async function load() {
  const from = new Date()
  // The same people as the agenda shows by default : the user, and the items nobody takes part in
  const [itemList, scheduleList] = await Promise.all([
    hasAgenda.value && workspace.current
      ? listAgendaItems({
          query: {
            from: from.toISOString(),
            to: addDays(from, AGENDA_DAYS).toISOString(),
            workspaces: String(workspace.current.id),
            participants: auth.user ? String(auth.user.id) : undefined,
          },
        })
      : null,
    hasSchedules.value ? listScheduledEvents() : null,
  ])
  items.value = itemList?.data ?? []
  schedules.value = scheduleList?.data ?? []
  now.value = Date.now()
  filled.value = entries.value.length > 0
}

watch(() => [workspace.current?.id, hasAgenda.value, hasSchedules.value], ([id]) => { if (id) void load() }, { immediate: true })

let reloadTimer: ReturnType<typeof setTimeout> | undefined
function onSignal(signal: WorkspaceSignal) {
  if (signal.type !== 'history.created') return
  const type = signal.history_type
  if (!type.startsWith('schedule.') && signal.origin !== 'schedule' && !(type.startsWith('agenda.item.') && type !== 'agenda.item.upcoming')) return
  clearTimeout(reloadTimer)
  reloadTimer = setTimeout(() => void load(), 300)
}

const stopListening = socket.onSignal(onSignal)
onBeforeUnmount(() => {
  stopListening()
  clearInterval(ticker)
  clearTimeout(reloadTimer)
})

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['day', 86_400_000],
  ['hour', 3_600_000],
  ['minute', 60_000],
]

function relative(entry: Entry): string {
  const delay = entry.at.getTime() - now.value
  if (delay <= 0) return t('home.ongoing')

  const format = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' })
  if (entry.allDay) {
    const today = new Date(now.value)
    today.setHours(0, 0, 0, 0)
    return format.format(Math.round((entry.at.getTime() - today.getTime()) / 86_400_000), 'day')
  }

  const [unit, size] = UNITS.find(([, size]) => delay >= size) ?? UNITS[UNITS.length - 1]!
  return format.format(Math.max(1, Math.round(delay / size)), unit)
}

function when(entry: Entry): string {
  if (entry.allDay) return d(entry.at, 'day')
  if (!entry.until) return d(entry.at, 'short')
  return `${d(entry.at, 'short')} – ${d(entry.until, 'time')}`
}

defineExpose({ load })
</script>

<template>
  <section v-if="entries.length" class="panel">
    <header class="panel-header">
      <h2>{{ t('home.upcoming') }}</h2>
    </header>

    <ul class="entries">
      <li v-for="entry in entries" :key="entry.key">
        <button type="button" class="entry" @click="router.push(entry.to)">
          <i :class="entry.icon" :style="{ color: entry.color }" aria-hidden="true" />
          <span class="entry-identity">
            <strong>{{ entry.title }}</strong>
            <code v-if="entry.key.startsWith('schedule:')">{{ entry.detail }}</code>
            <small v-else-if="entry.detail">{{ entry.detail }}</small>
          </span>
          <span class="entry-when">
            <time :datetime="entry.at.toISOString()">{{ when(entry) }}</time>
            <small>{{ relative(entry) }}</small>
          </span>
        </button>
      </li>
    </ul>
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

.panel-header h2 {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
}

.entries {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.entries li + li {
  border-top: 1px solid var(--p-content-border-color);
}

.entry {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.625rem 0.25rem;
  border: 0;
  border-radius: var(--p-content-border-radius);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.entry:hover {
  background: var(--p-content-hover-background);
}

.entry > .pi {
  flex: none;
}

.entry-identity {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.entry-identity strong {
  overflow-wrap: anywhere;
}

.entry-identity code,
.entry-identity small {
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
  overflow-wrap: anywhere;
}

.entry-when {
  flex: none;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.125rem;
  font-size: 0.875rem;
}

.entry-when small {
  color: var(--p-text-muted-color);
}
</style>
