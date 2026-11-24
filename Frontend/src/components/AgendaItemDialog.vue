<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import MultiSelect from 'primevue/multiselect'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import {
  createAgendaItem,
  deleteAgendaItem,
  listWorkspaceMembers,
  updateAgendaItem,
  type ListAgendaItemsResponse,
  type ListWorkspaceMembersResponse,
} from '../client'
import AgendaScopeDialog from './AgendaScopeDialog.vue'
import WorkspaceIcon from './WorkspaceIcon.vue'
import { useWorkspaceStore } from '../stores/workspace'
import {
  AGENDA_COLORS,
  FREQUENCIES,
  WEEKDAYS,
  addDays,
  agendaBody,
  dayOf,
  recurrenceOf,
  weekdayName,
  type AgendaDraft,
  type Frequency,
  type Recurrence,
} from '../utils/agenda'

type Item = ListAgendaItemsResponse[number]

const visible = defineModel<boolean>('visible', { required: true })
const props = defineProps<{ item: Item | null; draft: AgendaDraft; workspace: number }>()
const emit = defineEmits<{ changed: [] }>()

const { t, locale } = useI18n()
const store = useWorkspaceStore()

const form = ref({
  workspace: props.workspace,
  title: '',
  description: '',
  allDay: false,
  start: new Date(),
  end: new Date(),
  link: '',
  color: null as string | null,
  participants: [] as number[],
  frequency: null as Frequency | null,
  interval: 1,
  weekdays: [] as number[],
  until: null as Date | null,
})
const scopeDialog = ref<InstanceType<typeof AgendaScopeDialog> | null>(null)
const members = ref<ListWorkspaceMembersResponse>([])
const saving = ref(false)
const error = ref<string | null>(null)

const workspaceOptions = computed(() => store.workspaces ?? [])
const workspaceOf = (id: number) => workspaceOptions.value.find((workspace) => workspace.id === id)

// An all-day item is shown with its last day, the API keeps the day after it
watch(visible, (shown) => {
  if (!shown) return

  const { item, draft } = props
  const allDay = item?.all_day ?? draft.allDay
  const start = item ? new Date(item.starts_at) : draft.start
  const end = item ? new Date(item.ends_at) : draft.end
  const recurrence = item?.recurrence
  form.value = {
    workspace: item?.workspace ?? props.workspace,
    title: item?.title ?? '',
    description: item?.description ?? '',
    allDay,
    start: allDay ? new Date(item ? `${item.starts_at.slice(0, 10)}T00:00:00` : start) : start,
    end: allDay ? addDays(item ? new Date(`${item.ends_at.slice(0, 10)}T00:00:00`) : end, -1) : end,
    link: item?.link ?? '',
    color: item?.color ?? null,
    participants: item?.participants.map(({ id }) => id) ?? [],
    frequency: (recurrence?.frequency as Frequency | undefined) ?? null,
    interval: recurrence?.interval ?? 1,
    weekdays: recurrence?.weekdays ?? [],
    until: recurrence?.until ? new Date(`${recurrence.until}T00:00:00`) : null,
  }
  error.value = null
})

// ISO-8601 : 1 = Monday
const isoDay = (date: Date) => date.getDay() || 7

// A weekly item repeats on the day it starts until other days are chosen
watch(() => form.value.frequency, (frequency) => {
  if (frequency === 'weekly' && !form.value.weekdays.length) form.value.weekdays = [isoDay(form.value.start)]
})

const frequencyOptions = computed(() => [
  { value: null, label: t('agenda.recurrence.none') },
  ...FREQUENCIES.map((value) => ({ value, label: t(`agenda.recurrence.${value}`) })),
])

function toggleWeekday(day: number) {
  const days = form.value.weekdays
  form.value.weekdays = days.includes(day) ? days.filter((value) => value !== day) : [...days, day].sort()
}

function formRecurrence(): Recurrence | null {
  const { frequency, interval, weekdays, until } = form.value
  if (!frequency) return null

  return {
    frequency,
    interval: interval || 1,
    weekdays: frequency === 'weekly' ? [...(weekdays.length ? weekdays : [isoDay(form.value.start)])].sort() : [],
    until: until ? dayOf(until) : null,
  }
}

const sameRule = (a: Recurrence | null, b: Recurrence | null) => JSON.stringify(a) === JSON.stringify(b)

watch(() => (visible.value ? form.value.workspace : null), async (workspace, previous) => {
  if (workspace === null) return
  // Members of another workspace cannot take part
  if (previous !== null && previous !== undefined) form.value.participants = []

  const { data } = await listWorkspaceMembers({ path: { workspace } })
  members.value = data ?? []
})

const invalidDates = computed(() => (form.value.allDay ? form.value.end < form.value.start : form.value.end <= form.value.start))

async function save() {
  if (invalidDates.value) {
    error.value = t('agenda.invalidDates')
    return
  }
  if (form.value.until && dayOf(form.value.until) < dayOf(form.value.start)) {
    error.value = t('agenda.recurrence.invalidUntil')
    return
  }

  const recurrence = formRecurrence()
  // A new rule means nothing to a single occurrence
  const occurrence = props.item?.occurrence
  const scope = occurrence
    ? await scopeDialog.value?.ask('edit', sameRule(recurrence, recurrenceOf(props.item!)) ? ['occurrence', 'following', 'series'] : ['following', 'series'])
    : undefined
  if (occurrence && !scope) return

  saving.value = true
  error.value = null
  const { allDay, start, end } = form.value
  const body = {
    ...agendaBody({ allDay, start, end: allDay ? addDays(end, 1) : end }),
    title: form.value.title.trim(),
    description: form.value.description.trim() || null,
    link: form.value.link.trim() || null,
    color: form.value.color,
    participants: form.value.participants,
    recurrence,
  }
  const { response } = props.item
    ? await updateAgendaItem({ path: { item: props.item.id }, body: { ...body, ...(occurrence ? { occurrence, scope } : {}) } })
    : await createAgendaItem({ body: { ...body, workspace: form.value.workspace } })
  saving.value = false

  if (!response?.ok) {
    error.value = response?.status === 422 ? t('agenda.invalid') : t('agenda.saveError')
    return
  }

  visible.value = false
  emit('changed')
}

async function remove() {
  const item = props.item
  if (!item) return

  const occurrence = item.occurrence
  const scope = occurrence ? await scopeDialog.value?.ask('delete') : undefined
  if (occurrence ? !scope : !window.confirm(t('agenda.deleteConfirm', { title: item.title }))) return

  const { response } = await deleteAgendaItem({ path: { item: item.id }, query: occurrence ? { occurrence, scope } : undefined })
  if (!response?.ok) {
    error.value = t('agenda.deleteError')
    return
  }

  visible.value = false
  emit('changed')
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :header="t(item ? 'agenda.edit' : 'agenda.new')" :style="{ width: 'min(36rem, calc(100vw - 2rem))' }">
    <form class="item-form" @submit.prevent="save">
      <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

      <div class="field">
        <label for="agenda-workspace">{{ t('agenda.workspaceLabel') }}</label>
        <Select
          v-model="form.workspace"
          input-id="agenda-workspace"
          :options="workspaceOptions"
          option-label="name"
          option-value="id"
          :disabled="item !== null"
          fluid
        >
          <template #value="{ value }">
            <span v-if="workspaceOf(value)" class="workspace-option">
              <WorkspaceIcon :icon="workspaceOf(value)?.icon" :color="workspaceOf(value)?.accent_color" :size="18" />
              {{ workspaceOf(value)?.name }}
            </span>
          </template>
          <template #option="{ option }">
            <span class="workspace-option">
              <WorkspaceIcon :icon="option.icon" :color="option.accent_color" :size="18" />
              {{ option.name }}
            </span>
          </template>
        </Select>
        <small v-if="item" class="hint">{{ t('agenda.workspaceFixed') }}</small>
      </div>

      <div class="field">
        <label for="agenda-title">{{ t('agenda.titleLabel') }}</label>
        <InputText id="agenda-title" v-model="form.title" maxlength="255" required autofocus fluid />
      </div>

      <fieldset class="field colors">
        <legend>{{ t('agenda.colorLabel') }}</legend>
        <div class="swatches" role="radiogroup">
          <button
            type="button"
            role="radio"
            class="swatch none"
            :class="{ selected: form.color === null }"
            :aria-checked="form.color === null"
            :aria-label="t('agenda.noColor')"
            :title="t('agenda.noColor')"
            :style="{ '--swatch': workspaceOf(form.workspace)?.accent_color ?? 'var(--p-primary-color)' }"
            @click="form.color = null"
          />
          <button
            v-for="color in AGENDA_COLORS"
            :key="color"
            type="button"
            role="radio"
            class="swatch"
            :class="{ selected: form.color === color }"
            :aria-checked="form.color === color"
            :aria-label="color"
            :style="{ '--swatch': color, background: color }"
            @click="form.color = color"
          />
        </div>
      </fieldset>

      <label class="all-day">
        <Checkbox v-model="form.allDay" binary input-id="agenda-all-day" />
        {{ t('agenda.allDay') }}
      </label>

      <div class="field-row">
        <div class="field">
          <label for="agenda-start">{{ t('agenda.startLabel') }}</label>
          <DatePicker v-model="form.start" input-id="agenda-start" :show-time="!form.allDay" hour-format="24" :step-minute="5" required fluid />
        </div>
        <div class="field">
          <label for="agenda-end">{{ t(form.allDay ? 'agenda.lastDayLabel' : 'agenda.endLabel') }}</label>
          <DatePicker v-model="form.end" input-id="agenda-end" :show-time="!form.allDay" hour-format="24" :step-minute="5" :min-date="form.start" required fluid />
        </div>
      </div>

      <div class="field">
        <label for="agenda-frequency">{{ t('agenda.recurrence.label') }}</label>
        <Select v-model="form.frequency" input-id="agenda-frequency" :options="frequencyOptions" option-label="label" option-value="value" fluid />
      </div>

      <template v-if="form.frequency">
        <div class="field-row">
          <div class="field">
            <label for="agenda-interval">{{ t('agenda.recurrence.every') }}</label>
            <InputNumber
              v-model="form.interval"
              input-id="agenda-interval"
              :min="1"
              :max="99"
              show-buttons
              :suffix="` ${t(`agenda.recurrence.unit.${form.frequency}`, form.interval || 1)}`"
              fluid
            />
          </div>
          <div class="field">
            <label for="agenda-until">{{ t('agenda.recurrence.until') }}</label>
            <DatePicker
              v-model="form.until"
              input-id="agenda-until"
              :min-date="form.start"
              :placeholder="t('agenda.recurrence.forever')"
              show-button-bar
              fluid
            />
          </div>
        </div>

        <fieldset v-if="form.frequency === 'weekly'" class="field weekdays">
          <legend>{{ t('agenda.recurrence.weekdays') }}</legend>
          <div class="days">
            <button
              v-for="day in WEEKDAYS"
              :key="day"
              type="button"
              class="day"
              :class="{ selected: form.weekdays.includes(day) }"
              :aria-pressed="form.weekdays.includes(day)"
              :aria-label="weekdayName(day, locale, 'long')"
              :title="weekdayName(day, locale, 'long')"
              @click="toggleWeekday(day)"
            >
              {{ weekdayName(day, locale, 'narrow') }}
            </button>
          </div>
        </fieldset>
        <small v-if="item?.occurrence" class="hint">{{ t('agenda.recurrence.occurrenceHint') }}</small>
      </template>

      <div class="field">
        <label for="agenda-participants">{{ t('agenda.participantsLabel') }}</label>
        <MultiSelect
          v-model="form.participants"
          input-id="agenda-participants"
          :options="members"
          option-label="email"
          option-value="id"
          display="chip"
          filter
          fluid
        />
      </div>

      <div class="field">
        <label for="agenda-link">{{ t('agenda.linkLabel') }}</label>
        <InputText id="agenda-link" v-model="form.link" type="url" maxlength="2000" fluid />
      </div>

      <div class="field">
        <label for="agenda-description">{{ t('agenda.descriptionLabel') }}</label>
        <Textarea id="agenda-description" v-model="form.description" rows="4" fluid />
      </div>

      <div class="actions">
        <Button v-if="item" :label="t('agenda.delete')" icon="pi pi-trash" severity="danger" text @click="remove" />
        <Button type="submit" :label="t(item ? 'agenda.save' : 'agenda.create')" :loading="saving" />
      </div>
    </form>
  </Dialog>

  <AgendaScopeDialog ref="scopeDialog" />
</template>

<style scoped>
.item-form,
.field {
  display: flex;
  flex-direction: column;
}

.item-form {
  gap: 1rem;
}

.field {
  flex: 1;
  min-width: 0;
  gap: 0.375rem;
}

.field-row {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.field-row .field {
  flex-basis: 12rem;
}

.hint {
  color: var(--p-text-muted-color);
}

.colors {
  margin: 0;
  padding: 0;
  border: 0;
}

.colors legend {
  margin-bottom: 0.375rem;
  padding: 0;
}

.swatches {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.swatch {
  width: 1.75rem;
  height: 1.75rem;
  padding: 0;
  border: 0;
  border-radius: 50%;
  cursor: pointer;
  outline-offset: 2px;
}

.swatch.none {
  background: transparent;
  box-shadow: inset 0 0 0 2px var(--swatch);
}

.swatch.selected {
  outline: 2px solid var(--swatch);
}

.swatch:focus-visible {
  outline: 2px solid var(--p-focus-ring-color, var(--p-primary-color));
}

.weekdays {
  margin: 0;
  padding: 0;
  border: 0;
}

.weekdays legend {
  margin-bottom: 0.375rem;
  padding: 0;
}

.days {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
}

.day {
  width: 2.25rem;
  height: 2.25rem;
  padding: 0;
  color: var(--p-text-color);
  font: inherit;
  text-transform: capitalize;
  background: transparent;
  border: 1px solid var(--p-content-border-color);
  border-radius: 50%;
  cursor: pointer;
}

.day.selected {
  color: var(--p-primary-contrast-color);
  background: var(--p-primary-color);
  border-color: var(--p-primary-color);
}

.day:focus-visible {
  outline: 2px solid var(--p-focus-ring-color, var(--p-primary-color));
  outline-offset: 2px;
}

.all-day,
.workspace-option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>
