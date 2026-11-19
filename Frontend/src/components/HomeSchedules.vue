<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import { listScheduledEvents, type ListScheduledEventsResponse } from '../client'
import { useDeviceSocketStore, type WorkspaceSignal } from '../stores/deviceSocket'
import { useWorkspaceStore } from '../stores/workspace'

// The one-time schedules still to come : the recurring ones are chips of the circuit
const TICK_MS = 30_000

const { t, d, locale } = useI18n()
const router = useRouter()
const workspace = useWorkspaceStore()
const socket = useDeviceSocketStore()

const schedules = ref<ListScheduledEventsResponse>([])
// null until the first answer ; hidden while nothing is to come
const filled = defineModel<boolean | null>('filled', { default: null })
// A schedule whose minute has passed leaves the list without a reload
const now = ref(Date.now())
const ticker = setInterval(() => (now.value = Date.now()), TICK_MS)

const upcoming = computed(() =>
  schedules.value
    .filter((schedule) => schedule.run_at !== null && schedule.enabled && new Date(schedule.run_at).getTime() > now.value)
    .sort((a, b) => new Date(a.run_at!).getTime() - new Date(b.run_at!).getTime()),
)

watch(upcoming, (list) => { if (filled.value !== null) filled.value = list.length > 0 })

async function load() {
  const { data } = await listScheduledEvents()
  schedules.value = data ?? []
  now.value = Date.now()
  filled.value = upcoming.value.length > 0
}

watch(() => workspace.current?.id, (id) => { if (id) void load() }, { immediate: true })

// Created, changed, deleted, or run : the list follows
let reloadTimer: ReturnType<typeof setTimeout> | undefined
function onSignal(signal: WorkspaceSignal) {
  if (signal.type !== 'history.created') return
  if (!signal.history_type.startsWith('schedule.') && signal.origin !== 'schedule') return
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

function relative(date: string): string {
  const delay = new Date(date).getTime() - now.value
  const [unit, size] = UNITS.find(([, size]) => delay >= size) ?? UNITS[UNITS.length - 1]!
  return new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' }).format(Math.max(1, Math.round(delay / size)), unit)
}

defineExpose({ load })
</script>

<template>
  <section v-if="upcoming.length" class="panel">
    <header class="panel-header">
      <h2>{{ t('home.upcomingSchedules') }}</h2>
      <Button :label="t('home.manage')" severity="secondary" text size="small" @click="router.push({ name: 'automation', query: { tab: 'schedules' } })" />
    </header>

    <ul class="schedules">
      <li v-for="schedule in upcoming" :key="schedule.id" class="schedule">
        <i class="pi pi-calendar-clock" aria-hidden="true" />
        <span class="schedule-identity">
          <strong>{{ schedule.name }}</strong>
          <code>{{ schedule.type }}</code>
        </span>
        <span class="schedule-when">
          <time :datetime="schedule.run_at!">{{ d(new Date(schedule.run_at!), 'short') }}</time>
          <small>{{ relative(schedule.run_at!) }}</small>
        </span>
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

.schedules {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}

.schedule {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.625rem 0;
  border-top: 1px solid var(--p-content-border-color);
}

.schedule:first-child {
  border-top: 0;
  padding-top: 0;
}

.schedule > .pi {
  flex: none;
  color: var(--p-primary-color);
}

.schedule-identity {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.schedule-identity strong {
  overflow-wrap: anywhere;
}

.schedule-identity code {
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
  overflow-wrap: anywhere;
}

.schedule-when {
  flex: none;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.125rem;
  font-size: 0.875rem;
}

.schedule-when small {
  color: var(--p-text-muted-color);
}
</style>
