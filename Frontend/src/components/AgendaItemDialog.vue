<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
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
import WorkspaceIcon from './WorkspaceIcon.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { AGENDA_COLORS, addDays, agendaBody, type AgendaDraft } from '../utils/agenda'

type Item = ListAgendaItemsResponse[number]

const visible = defineModel<boolean>('visible', { required: true })
const props = defineProps<{ item: Item | null; draft: AgendaDraft; workspace: number }>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
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
})
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
  }
  error.value = null
})

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
  }
  const { response } = props.item
    ? await updateAgendaItem({ path: { item: props.item.id }, body })
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
  if (!item || !window.confirm(t('agenda.deleteConfirm', { title: item.title }))) return

  const { response } = await deleteAgendaItem({ path: { item: item.id } })
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
