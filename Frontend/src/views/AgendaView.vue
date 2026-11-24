<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction'
import timeGridPlugin from '@fullcalendar/timegrid'
import enGbLocale from '@fullcalendar/core/locales/en-gb'
import frLocale from '@fullcalendar/core/locales/fr'
import type { CalendarOptions, DatesSetArg, DateSelectArg, EventClickArg, EventDropArg, EventInput, EventSourceFuncArg } from '@fullcalendar/core'
import type { EventResizeDoneArg } from '@fullcalendar/interaction'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Message from 'primevue/message'
import SelectButton from 'primevue/selectbutton'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import { listAgendaItems, listAgendaSchedules, listWorkspaceMembers, updateAgendaItem, type ListWorkspaceMembersResponse } from '../client'
import AgendaItemDialog from '../components/AgendaItemDialog.vue'
import WorkspaceIcon from '../components/WorkspaceIcon.vue'
import { useAuthStore } from '../stores/auth'
import { useDeviceSocketStore } from '../stores/deviceSocket'
import { useWorkspaceStore } from '../stores/workspace'
import { addDays, dayOf, itemBody, itemEvent, scheduleEvent, type AgendaDraft, type AgendaItem, type ScheduleOccurrence } from '../utils/agenda'
import { useMediaQuery } from '../utils/mediaQuery'
import { storedFlag } from '../utils/storedFlag'
import { storedIds } from '../utils/storedIds'

const { t, locale } = useI18n()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const store = useWorkspaceStore()
const socket = useDeviceSocketStore()
const phone = useMediaQuery('(max-width: 48rem)')

const calendar = ref<InstanceType<typeof FullCalendar> | null>(null)
const error = ref<string | null>(null)
const optionsOpen = ref(false)
const focusedDate = ref(new Date())

if (store.workspaces === null) void store.loadWorkspaces()

const workspaces = computed(() => store.workspaces ?? [])
const showSchedules = storedFlag(ref('tower.agenda.schedules'))
const selectedWorkspaces = storedIds(ref('tower.agenda.workspaces'))
const shownWorkspaces = computed(() => {
  const known = (selectedWorkspaces.value ?? []).filter((id) => workspaces.value.some((workspace) => workspace.id === id))
  return known.length ? known : store.current ? [store.current.id] : []
})

function colorOf(workspace: number): string {
  return workspaces.value.find(({ id }) => id === workspace)?.accent_color ?? 'var(--p-primary-color)'
}

function toggleWorkspace(id: number, checked: boolean) {
  const current = shownWorkspaces.value
  selectedWorkspaces.value = checked ? [...new Set([...current, id])] : current.filter((value) => value !== id)
}

const members = ref<ListWorkspaceMembersResponse>([])
const selectedMembers = storedIds(computed(() => `tower.agenda.members.${store.current?.id ?? 0}`))
const shownMembers = computed(() => selectedMembers.value ?? (auth.user ? [auth.user.id] : []))

watch(() => store.current?.id, async (workspace) => {
  members.value = []
  if (!workspace) return

  const { data } = await listWorkspaceMembers({ path: { workspace } })
  members.value = data ?? []
}, { immediate: true })

function toggleMember(id: number, checked: boolean) {
  const current = shownMembers.value
  selectedMembers.value = checked ? [...new Set([...current, id])] : current.filter((value) => value !== id)
}

async function fetchEvents(range: EventSourceFuncArg): Promise<EventInput[]> {
  error.value = null
  const query = { from: range.start.toISOString(), to: range.end.toISOString(), workspaces: shownWorkspaces.value.join(',') }
  const [items, schedules] = await Promise.all([
    // Nobody checked : nobody is filtered out
    listAgendaItems({ query: { ...query, participants: shownMembers.value.join(',') || undefined } }),
    showSchedules.value ? listAgendaSchedules({ query }) : null,
  ])
  if (!items.data || (schedules && !schedules.data)) error.value = t('agenda.loadError')

  return [
    ...(items.data ?? []).map((item) => itemEvent(item, item.color ?? colorOf(item.workspace))),
    ...((schedules?.data ?? []) as ScheduleOccurrence[]).map((occurrence) =>
      scheduleEvent(occurrence, colorOf(occurrence.workspace), t('agenda.frequentSchedule', { name: occurrence.name, runs: occurrence.runs })),
    ),
  ]
}

function refetch() {
  calendar.value?.getApi().refetchEvents()
}

watch([shownWorkspaces, shownMembers, showSchedules], refetch, { deep: true })

const stopListening = socket.onSignal((signal) => {
  if (signal.type === 'history.created' && signal.history_type.startsWith('agenda.item.') && signal.history_type !== 'agenda.item.upcoming') refetch()
})
onBeforeUnmount(stopListening)

function goTo(date: Date | Date[] | (Date | null)[] | null | undefined) {
  if (!(date instanceof Date)) return

  focusedDate.value = date
  // A day without its time : FullCalendar reads it in its own timezone, never one day off
  calendar.value?.getApi().gotoDate(dayOf(date))
  if (phone.value) optionsOpen.value = false
}

type CalendarView = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay'

const title = ref('')
const view = ref<CalendarView>(phone.value ? 'timeGridDay' : 'timeGridWeek')
const viewOptions = computed(() => [
  { value: 'dayGridMonth', label: t('agenda.month') },
  { value: 'timeGridWeek', label: t('agenda.week') },
  { value: 'timeGridDay', label: t('agenda.day') },
])

function changeView(value: CalendarView | null) {
  if (value) calendar.value?.getApi().changeView(value)
}

// The day chosen in the month stays highlighted as long as it is shown
function onDatesSet({ view: shownView }: DatesSetArg) {
  title.value = shownView.title
  view.value = shownView.type as CalendarView

  const shown = (date: Date) => shownView.currentStart <= date && date < shownView.currentEnd
  if (shown(focusedDate.value)) return

  const today = new Date()
  focusedDate.value = shown(today) ? today : shownView.currentStart
}

const dialogVisible = ref(false)
const editing = ref<AgendaItem | null>(null)
const draft = ref<AgendaDraft>({ start: new Date(), end: new Date(), allDay: false })

function openNew(value?: AgendaDraft) {
  const start = new Date()
  start.setMinutes(Math.ceil(start.getMinutes() / 15) * 15, 0, 0)
  draft.value = value ?? { start, end: new Date(start.getTime() + 60 * 60_000), allDay: false }
  editing.value = null
  dialogVisible.value = true
}

function onSelect(selection: DateSelectArg) {
  calendar.value?.getApi().unselect()
  openNew({ start: selection.start, end: selection.end, allDay: selection.allDay })
}

function onEventClick({ event, jsEvent }: EventClickArg) {
  jsEvent.preventDefault()
  if (event.extendedProps.occurrence) {
    void router.push({ name: 'automation', query: { tab: 'schedules' } })
    return
  }

  editing.value = event.extendedProps.item as AgendaItem
  dialogVisible.value = true
}

async function onMoved({ event, revert }: EventDropArg | EventResizeDoneArg) {
  const item = event.extendedProps.item as AgendaItem
  const start = event.start!
  const end = event.end ?? (event.allDay ? addDays(start, 1) : new Date(start.getTime() + 60 * 60_000))
  const { data } = await updateAgendaItem({ path: { item: item.id }, body: itemBody(item, { start, end, allDay: event.allDay }) })

  if (!data) {
    revert()
    toast.add({ severity: 'error', summary: t('agenda.saveError'), life: 4000 })
    return
  }
  event.setExtendedProp('item', data)
}

const options = computed<CalendarOptions>(() => ({
  plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
  initialView: view.value,
  locale: locale.value === 'fr' ? frLocale : enGbLocale,
  headerToolbar: false,
  height: '100%',
  nowIndicator: true,
  scrollTime: '08:00:00',
  editable: true,
  selectable: true,
  selectMirror: true,
  dayMaxEvents: true,
  forceEventDuration: true,
  defaultTimedEventDuration: '01:00',
  events: fetchEvents,
  datesSet: onDatesSet,
  select: onSelect,
  eventClick: onEventClick,
  eventDrop: onMoved,
  eventResize: onMoved,
}))
</script>

<template>
  <header class="agenda-header">
    <h1 class="page-title">{{ t('agenda.title') }}</h1>
    <Button
      v-if="phone"
      :label="t('agenda.options')"
      icon="pi pi-sliders-h"
      severity="secondary"
      size="small"
      :aria-expanded="optionsOpen"
      @click="optionsOpen = !optionsOpen"
    />
  </header>

  <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

  <div class="agenda-layout">
    <aside v-show="!phone || optionsOpen" class="side">
      <Button :label="t('agenda.new')" icon="pi pi-plus" fluid @click="openNew()" />

      <DatePicker :model-value="focusedDate" inline show-week class="month" @update:model-value="goTo" />

      <section v-if="members.length" class="group">
        <h2 class="group-title">{{ t('agenda.membersLabel', { workspace: store.current?.name }) }}</h2>
        <label v-for="member in members" :key="member.id" class="option">
          <Checkbox :model-value="shownMembers.includes(member.id)" binary @update:model-value="toggleMember(member.id, $event)" />
          <span class="option-label">{{ member.email }}<template v-if="member.id === auth.user?.id"> {{ t('agenda.you') }}</template></span>
        </label>
        <small class="hint">{{ t('agenda.membersHint') }}</small>
      </section>

      <section v-if="workspaces.length > 1" class="group">
        <h2 class="group-title">{{ t('agenda.workspacesLabel') }}</h2>
        <label v-for="workspace in workspaces" :key="workspace.id" class="option">
          <Checkbox :model-value="shownWorkspaces.includes(workspace.id)" binary @update:model-value="toggleWorkspace(workspace.id, $event)" />
          <span class="swatch" :style="{ background: colorOf(workspace.id) }" aria-hidden="true" />
          <WorkspaceIcon :icon="workspace.icon" :color="workspace.accent_color" :size="18" />
          <span class="option-label">{{ workspace.name }}</span>
        </label>
      </section>

      <section class="group">
        <h2 class="group-title">{{ t('agenda.displayLabel') }}</h2>
        <label class="option">
          <ToggleSwitch v-model="showSchedules" />
          <span class="option-label">{{ t('agenda.showSchedules') }}</span>
        </label>
      </section>
    </aside>

    <section class="calendar">
      <header class="toolbar">
        <div class="navigation">
          <Button icon="pi pi-chevron-left" severity="secondary" text rounded :aria-label="t('agenda.previous')" @click="calendar?.getApi().prev()" />
          <Button icon="pi pi-chevron-right" severity="secondary" text rounded :aria-label="t('agenda.next')" @click="calendar?.getApi().next()" />
          <Button :label="t('agenda.today')" severity="secondary" outlined size="small" @click="calendar?.getApi().today()" />
        </div>
        <h2 class="calendar-title">{{ title }}</h2>
        <SelectButton
          :model-value="view"
          :options="viewOptions"
          option-label="label"
          option-value="value"
          :allow-empty="false"
          size="small"
          class="views"
          @update:model-value="changeView"
        />
      </header>
      <div class="grid">
        <FullCalendar ref="calendar" :options="options" />
      </div>
    </section>
  </div>

  <AgendaItemDialog v-model:visible="dialogVisible" :item="editing" :draft="draft" :workspace="store.current?.id ?? shownWorkspaces[0] ?? 0" @changed="refetch" />
</template>

<style scoped>
.agenda-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

/* Down to the bottom of the page : the time grid scrolls inside it */
.agenda-layout {
  flex: 1;
  display: grid;
  grid-template-columns: 17rem minmax(0, 1fr);
  gap: 1.25rem;
  min-height: 36rem;
}

.side {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  min-width: 0;
}

.month {
  width: 100%;
}

.month :deep(.p-datepicker-panel) {
  width: 100%;
  min-width: 0;
  padding: 0.5rem;
}

.month :deep(.p-datepicker-day) {
  width: 2rem;
  height: 2rem;
}

.group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.group-title {
  margin: 0;
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  cursor: pointer;
}

.option-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hint {
  color: var(--p-text-muted-color);
}

.swatch {
  flex: none;
  width: 0.625rem;
  height: 0.625rem;
  border-radius: 50%;
}

.calendar {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 36rem;
  overflow: hidden;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.toolbar {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--p-content-border-color);
}

.navigation {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.navigation .p-button-outlined {
  margin-left: 0.5rem;
}

.calendar-title {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
  text-transform: capitalize;
  white-space: nowrap;
}

.views {
  justify-self: end;
}

.grid {
  flex: 1;
  min-height: 0;
}

/* The section draws the outer frame and its rounding : FullCalendar keeps the inner lines only, lightened */
.grid :deep(.fc) {
  --fc-border-color: color-mix(in srgb, var(--p-content-border-color) 70%, transparent);
  --fc-page-bg-color: var(--p-content-background);
  --fc-neutral-bg-color: var(--p-content-hover-background);
  --fc-today-bg-color: color-mix(in srgb, var(--p-primary-color) 6%, transparent);
  --fc-highlight-color: color-mix(in srgb, var(--p-primary-color) 14%, transparent);
  --fc-now-indicator-color: var(--p-primary-color);
  --fc-event-bg-color: var(--p-primary-color);
  --fc-event-border-color: var(--p-primary-color);
  --fc-event-text-color: var(--p-primary-contrast-color);
  --fc-small-font-size: 0.75rem;
  height: 100%;
  font-size: 0.8125rem;
}

.grid :deep(.fc-theme-standard .fc-scrollgrid) {
  border: 0;
}

.grid :deep(.fc-theme-standard td:last-child),
.grid :deep(.fc-theme-standard th:last-child) {
  border-right: 0;
}

.grid :deep(.fc-col-header-cell) {
  padding: 0.5rem 0;
  border-left-color: transparent;
  border-right-color: transparent;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
}

.grid :deep(.fc-col-header-cell.fc-day-today) {
  color: var(--p-primary-color);
}

.grid :deep(a.fc-col-header-cell-cushion),
.grid :deep(a.fc-daygrid-day-number) {
  color: inherit;
  text-decoration: none;
}

.grid :deep(.fc-timegrid-slot) {
  height: 2.5rem;
}

.grid :deep(.fc-timegrid-slot-minor) {
  border-top-color: transparent;
}

.grid :deep(.fc-timegrid-slot-label),
.grid :deep(.fc-timegrid-axis) {
  border-color: transparent;
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
}

.grid :deep(.fc-daygrid-day-number) {
  padding: 0.375rem 0.5rem;
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.grid :deep(.fc-day-today .fc-daygrid-day-number) {
  color: var(--p-primary-color);
  font-weight: 600;
}

.grid :deep(.fc-event) {
  border-radius: 6px;
  box-shadow: none;
}

.grid :deep(.fc-timegrid-event) {
  padding: 0.125rem 0.25rem;
  border-width: 0 0 0 3px;
}

.grid :deep(.fc-timegrid-event-harness-inset .fc-timegrid-event) {
  box-shadow: 0 0 0 1px var(--p-content-background);
}

.grid :deep(.fc-daygrid-block-event) {
  padding: 0 0.25rem;
}

.grid :deep(.fc-daygrid-dot-event:hover),
.grid :deep(.fc-more-link:hover) {
  background: var(--p-content-hover-background);
}

.grid :deep(.fc-more-link) {
  color: var(--p-text-muted-color);
}

.grid :deep(.schedule-occurrence) {
  border-width: 1px;
  border-style: dashed;
  cursor: pointer;
}

@media (max-width: 48rem) {
  .agenda-layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .toolbar {
    grid-template-columns: auto 1fr;
    padding: 0.5rem 0.75rem;
  }

  .calendar-title {
    justify-self: end;
    font-size: 0.9375rem;
  }

  .views {
    grid-column: 1 / -1;
    justify-self: stretch;
  }

  .views :deep(.p-togglebutton) {
    flex: 1;
  }
}
</style>
