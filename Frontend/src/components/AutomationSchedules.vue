<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import {
  createScheduledEvent,
  deleteScheduledEvent,
  listScheduledEvents,
  triggerScheduledEvent,
  updateScheduledEvent,
  type ListScheduledEventsResponse,
} from '../client'
import EventFields from './EventFields.vue'
import EmptyState from './EmptyState.vue'
import { useDeviceSocketStore, type WorkspaceSignal } from '../stores/deviceSocket'
import { useWorkspaceStore } from '../stores/workspace'
import { EVENT_TYPE } from '../utils/historyLine'
import {
  DEFAULT_RECURRENCE,
  FREQUENCIES,
  WEEKDAYS,
  fromCron,
  timeOf,
  toCron,
  toServerDateTime,
  type Frequency,
  type Recurrence,
} from '../utils/schedule'

type Schedule = ListScheduledEventsResponse[number]
type Mode = 'recurring' | 'once'
// A cron set through the API that the form cannot rebuild : kept and edited as is
type FormFrequency = Frequency | 'custom'

const { t, d, locale } = useI18n()
const toast = useToast()
const workspace = useWorkspaceStore()
const socket = useDeviceSocketStore()

const schedules = ref<ListScheduledEventsResponse>([])
const loaded = ref(false)
const listError = ref<string | null>(null)

// The form serves the creation (`editing` null) and the edition
const formVisible = ref(false)
const editing = ref<Schedule | null>(null)
const form = ref({
  name: '',
  type: '',
  payload: '',
  mode: 'recurring' as Mode,
  frequency: 'daily' as FormFrequency,
  recurrence: { ...DEFAULT_RECURRENCE },
  cron: '',
  runAt: null as Date | null,
})
const saving = ref(false)
const formError = ref<string | null>(null)

const modeOptions = computed(() => [
  { value: 'recurring', label: t('schedules.mode.recurring') },
  { value: 'once', label: t('schedules.mode.once') },
])
const frequencyOptions = computed(() => [
  ...FREQUENCIES.map((value) => ({ value, label: t(`schedules.frequency.${value}`) })),
  ...(form.value.frequency === 'custom' ? [{ value: 'custom', label: t('schedules.frequency.custom') }] : []),
])
const weekdayOptions = computed(() => WEEKDAYS.map((value) => ({ value, label: weekdayName(value, 'short'), title: weekdayName(value, 'long') })))

// The time pickers work on a Date : only its hours and minutes are read
const recurrenceTime = computed<Date>({
  get: () => new Date(2000, 0, 1, form.value.recurrence.hour, form.value.recurrence.minute),
  set: (time) => {
    if (!time) return
    form.value.recurrence.hour = time.getHours()
    form.value.recurrence.minute = time.getMinutes()
  },
})

function weekdayName(weekday: number, width: 'long' | 'short'): string {
  // 2024-01-07 is a Sunday
  return new Intl.DateTimeFormat(locale.value, { weekday: width }).format(new Date(2024, 0, 7 + weekday))
}

function weekdaysLabel(weekdays: number[]): string {
  const ordered = WEEKDAYS.filter((weekday) => weekdays.includes(weekday))
  return new Intl.ListFormat(locale.value, { type: 'conjunction' }).format(ordered.map((weekday) => weekdayName(weekday, 'long')))
}

async function load() {
  listError.value = null
  const { data } = await listScheduledEvents()
  loaded.value = true
  if (data) schedules.value = data
  else listError.value = t('schedules.loadError')
}

watch(() => workspace.current?.id, (id) => { if (id) void load() }, { immediate: true })

// A triggered schedule changes its last run, a one-time one is disabled
const stopListening = socket.onSignal((signal: WorkspaceSignal) => {
  if (signal.type === 'history.created' && signal.history_type === EVENT_TYPE) void load()
})
onBeforeUnmount(stopListening)

const describeRecurrence: Record<Frequency, (recurrence: Recurrence) => string> = {
  minute: () => t('schedules.every.minute'),
  minutes: ({ step }) => t('schedules.every.minutes', { step }),
  hourly: ({ minute }) => t('schedules.every.hourly', { minute: String(minute).padStart(2, '0') }),
  daily: (recurrence) => t('schedules.every.daily', { time: timeOf(recurrence) }),
  weekly: (recurrence) => t('schedules.every.weekly', { days: weekdaysLabel(recurrence.weekdays), time: timeOf(recurrence) }),
  monthly: (recurrence) => t('schedules.every.monthly', { day: recurrence.monthDay, time: timeOf(recurrence) }),
}

function describe(schedule: Schedule): string {
  if (schedule.run_at) return t('schedules.onceAt', { date: d(new Date(schedule.run_at), 'short') })

  const recurrence = fromCron(schedule.cron)
  return recurrence ? describeRecurrence[recurrence.frequency](recurrence) : t('schedules.custom', { cron: schedule.cron })
}

function isOver(schedule: Schedule): boolean {
  return schedule.run_at !== null && new Date(schedule.run_at) <= new Date()
}

function timingOf(schedule: Schedule): { cron: string | null; run_at: string | null } {
  return schedule.run_at
    ? { cron: null, run_at: toServerDateTime(new Date(schedule.run_at)) }
    : { cron: schedule.cron, run_at: null }
}

function openForm(schedule: Schedule | null) {
  editing.value = schedule
  const recurrence = schedule && !schedule.run_at ? fromCron(schedule.cron) : null
  form.value = {
    name: schedule?.name ?? '',
    type: schedule?.type ?? '',
    payload: schedule?.payload ?? '',
    mode: schedule?.run_at ? 'once' : 'recurring',
    frequency: schedule && !schedule.run_at && !recurrence ? 'custom' : (recurrence?.frequency ?? 'daily'),
    recurrence: recurrence ?? { ...DEFAULT_RECURRENCE },
    cron: schedule?.cron ?? '',
    runAt: schedule?.run_at ? new Date(schedule.run_at) : null,
  }
  formError.value = null
  formVisible.value = true
}

function formTiming(): { cron: string | null; run_at: string | null } {
  const { mode, frequency, recurrence, cron, runAt } = form.value
  if (mode === 'once') return { cron: null, run_at: runAt ? toServerDateTime(runAt) : null }
  if (frequency === 'custom') return { cron: cron.trim(), run_at: null }

  return { cron: toCron({ ...recurrence, frequency }), run_at: null }
}

async function save() {
  saving.value = true
  formError.value = null

  const body = {
    name: form.value.name.trim(),
    type: form.value.type.trim(),
    payload: form.value.payload.trim() || '{}',
    ...formTiming(),
  }
  const target = editing.value
  // A one-time schedule whose date is past comes back to life with its new date
  const enabled = target ? target.enabled || (body.run_at !== null && isOver(target)) : true

  const { response } = target
    ? await updateScheduledEvent({ path: { schedule: target.id }, body: { ...body, enabled } })
    : await createScheduledEvent({ body })
  saving.value = false

  if (!response?.ok) {
    formError.value = response?.status === 422 ? t('schedules.invalid') : t('schedules.saveError')
    return
  }

  formVisible.value = false
  await load()
}

async function setEnabled(schedule: Schedule, enabled: boolean) {
  listError.value = null
  const { name, type, payload } = schedule
  const { data } = await updateScheduledEvent({ path: { schedule: schedule.id }, body: { name, type, payload, ...timingOf(schedule), enabled } })
  if (!data) listError.value = t('schedules.saveError')
  await load()
}

async function launch(schedule: Schedule) {
  listError.value = null
  const { response } = await triggerScheduledEvent({ path: { schedule: schedule.id } })
  if (response?.ok) toast.add({ severity: 'success', summary: t('events.sent', { type: schedule.type }), life: 3000 })
  else listError.value = t('schedules.launchError')
}

async function remove(schedule: Schedule) {
  if (!window.confirm(t('schedules.deleteConfirm', { name: schedule.name }))) return

  listError.value = null
  const { response } = await deleteScheduledEvent({ path: { schedule: schedule.id } })
  if (!response?.ok) listError.value = t('schedules.deleteError')
  await load()
}
</script>

<template>
  <EmptyState v-if="loaded && !schedules.length && !listError" icon="pi pi-clock" :title="t('schedules.emptyTitle')" :hint="t('schedules.emptyHint')">
    <Button :label="t('schedules.new')" icon="pi pi-plus" @click="openForm(null)" />
  </EmptyState>

  <section v-else class="panel">
    <header class="panel-header">
      <p class="description">{{ t('schedules.description') }}</p>
      <Button :label="t('schedules.new')" icon="pi pi-plus" size="small" @click="openForm(null)" />
    </header>

    <Message v-if="listError" class="panel-error" severity="error" :closable="false">{{ listError }}</Message>

    <div v-if="schedules.length" class="table-wrapper">
      <table class="schedules">
        <thead>
          <tr>
            <th>{{ t('schedules.enabled') }}</th>
            <th>{{ t('schedules.nameLabel') }}</th>
            <th>{{ t('schedules.eventLabel') }}</th>
            <th>{{ t('schedules.when') }}</th>
            <th>{{ t('schedules.lastRun') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="schedule in schedules" :key="schedule.id" :class="{ 'schedule-disabled': !schedule.enabled }">
            <td>
              <!-- A one-time schedule whose date is past cannot run again : edit it to set a new date -->
              <ToggleSwitch
                :model-value="schedule.enabled"
                :disabled="!schedule.enabled && isOver(schedule)"
                :aria-label="schedule.enabled ? t('schedules.disable') : t('schedules.enable')"
                :title="!schedule.enabled && isOver(schedule) ? t('schedules.over') : schedule.enabled ? t('schedules.enabled') : t('schedules.disabled')"
                @update:model-value="setEnabled(schedule, $event)"
              />
            </td>
            <td class="schedule-name"><strong>{{ schedule.name }}</strong></td>
            <td><code class="schedule-event">{{ schedule.type }}</code></td>
            <td>{{ describe(schedule) }}</td>
            <td class="schedule-last-run">
              {{ schedule.last_run_at ? d(new Date(schedule.last_run_at), 'short') : t('schedules.neverRun') }}
            </td>
            <td>
              <div class="schedule-buttons">
                <Button icon="pi pi-bolt" severity="secondary" text size="small" :aria-label="t('schedules.launch')" :title="t('schedules.launch')" @click="launch(schedule)" />
                <Button icon="pi pi-pencil" severity="secondary" text size="small" :aria-label="t('schedules.edit')" :title="t('schedules.edit')" @click="openForm(schedule)" />
                <Button icon="pi pi-trash" severity="secondary" text size="small" :aria-label="t('schedules.deleteButton')" :title="t('schedules.deleteButton')" @click="remove(schedule)" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <Dialog
    v-model:visible="formVisible"
    modal
    :header="editing ? t('schedules.edit') : t('schedules.new')"
    :style="{ width: '32rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <form class="schedule-form" @submit.prevent="save">
      <Message v-if="formError" severity="error" :closable="false">{{ formError }}</Message>
      <div class="field">
        <label for="schedule-name">{{ t('schedules.nameLabel') }}</label>
        <InputText id="schedule-name" v-model="form.name" maxlength="100" required autofocus fluid />
      </div>
      <EventFields v-model:type="form.type" v-model:payload="form.payload" />

      <SelectButton v-model="form.mode" :options="modeOptions" option-label="label" option-value="value" :allow-empty="false" :aria-label="t('schedules.when')" />

      <template v-if="form.mode === 'recurring'">
        <div class="field">
          <label for="schedule-frequency">{{ t('schedules.frequencyLabel') }}</label>
          <Select v-model="form.frequency" input-id="schedule-frequency" :options="frequencyOptions" option-label="label" option-value="value" fluid />
        </div>

        <div v-if="form.frequency === 'minutes'" class="field">
          <label for="schedule-step">{{ t('schedules.stepLabel') }}</label>
          <InputNumber v-model="form.recurrence.step" input-id="schedule-step" :min="1" :max="59" :allow-empty="false" fluid />
        </div>

        <div v-if="form.frequency === 'hourly'" class="field">
          <label for="schedule-minute">{{ t('schedules.minuteLabel') }}</label>
          <InputNumber v-model="form.recurrence.minute" input-id="schedule-minute" :min="0" :max="59" :allow-empty="false" fluid />
        </div>

        <div v-if="form.frequency === 'weekly'" class="field">
          <span id="schedule-weekdays" class="field-label">{{ t('schedules.weekdaysLabel') }}</span>
          <!-- allow-empty false keeps the last day checked : a week without a day never runs -->
          <SelectButton
            v-model="form.recurrence.weekdays"
            class="weekdays"
            :options="weekdayOptions"
            option-label="label"
            option-value="value"
            multiple
            :allow-empty="false"
            aria-labelledby="schedule-weekdays"
          >
            <template #option="{ option }">
              <span :title="option.title">{{ option.label }}</span>
            </template>
          </SelectButton>
        </div>

        <div v-if="form.frequency === 'monthly'" class="field">
          <label for="schedule-month-day">{{ t('schedules.monthDayLabel') }}</label>
          <InputNumber v-model="form.recurrence.monthDay" input-id="schedule-month-day" :min="1" :max="31" :allow-empty="false" fluid />
          <small>{{ t('schedules.monthDayHint') }}</small>
        </div>

        <div v-if="form.frequency === 'daily' || form.frequency === 'weekly' || form.frequency === 'monthly'" class="field">
          <label for="schedule-time">{{ t('schedules.timeLabel') }}</label>
          <DatePicker v-model="recurrenceTime" input-id="schedule-time" time-only hour-format="24" fluid />
        </div>

        <div v-if="form.frequency === 'custom'" class="field">
          <label for="schedule-cron">{{ t('schedules.cronLabel') }}</label>
          <InputText id="schedule-cron" v-model="form.cron" class="schedule-cron" maxlength="100" required fluid />
        </div>
      </template>

      <div v-else class="field">
        <label for="schedule-run-at">{{ t('schedules.runAtLabel') }}</label>
        <DatePicker v-model="form.runAt" input-id="schedule-run-at" show-time hour-format="24" :min-date="new Date()" required fluid />
      </div>

      <small class="timezone-hint">{{ t('schedules.timezoneHint') }}</small>
      <Button class="form-submit" type="submit" :label="editing ? t('schedules.save') : t('schedules.create')" :loading="saving" />
    </form>
  </Dialog>
</template>

<style scoped>
.panel {
  max-width: 72rem;
  margin-bottom: 1.25rem;
  padding: 1.25rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.panel-header :deep(button) {
  flex: none;
}

.panel-error {
  margin-bottom: 0.75rem;
}

.description {
  margin: 0;
  color: var(--p-text-muted-color);
}

.table-wrapper {
  overflow-x: auto;
}

.schedules {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.schedules th,
.schedules td {
  padding: 0.375rem 0.5rem;
  border-bottom: 1px solid var(--p-content-border-color);
  text-align: left;
  vertical-align: middle;
}

.schedules th {
  color: var(--p-text-muted-color);
  font-weight: 500;
  white-space: nowrap;
}

.schedules tbody tr:last-child td {
  border-bottom: 0;
}

.schedule-disabled td:not(:first-child):not(:last-child) {
  opacity: 0.6;
}

.schedule-name {
  overflow-wrap: anywhere;
}

.schedule-event {
  padding: 0.0625rem 0.3125rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  font-size: 0.8125rem;
}

.schedule-last-run {
  color: var(--p-text-muted-color);
  white-space: nowrap;
}

.schedule-buttons {
  display: flex;
  justify-content: flex-end;
  gap: 0.125rem;
}

.schedule-form,
.field {
  display: flex;
  flex-direction: column;
}

.schedule-form {
  gap: 1rem;
}

.field {
  gap: 0.375rem;
}

.field small,
.timezone-hint {
  color: var(--p-text-muted-color);
}

.weekdays :deep(.p-togglebutton) {
  flex: 1;
  text-transform: capitalize;
}

.schedule-cron {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.form-submit {
  align-self: flex-end;
}

@media (max-width: 30rem) {
  .panel-header {
    flex-direction: column;
  }
}
</style>
