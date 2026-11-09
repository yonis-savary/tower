<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { createTask, listTaskHistory, updateTask, type ListTaskHistoryResponse } from '../client'
import { TASK_STATUSES, useTasksStore, type Task, type TaskStatus } from '../stores/tasks'
import { taskChange } from '../utils/taskChange'

const visible = defineModel<boolean>('visible', { required: true })
// Without task, the dialog creates one in `sprint`
const props = defineProps<{ task: Task | null; sprint: number | null }>()

const { t, te, d } = useI18n()
const store = useTasksStore()

const form = ref({ title: '', description: '', status: 'todo' as TaskStatus, sprint: null as number | null })
const history = ref<ListTaskHistoryResponse>([])
const saving = ref(false)
const error = ref<string | null>(null)

const statusOptions = computed(() => TASK_STATUSES.map((value) => ({ value, label: t(`tasks.status.${value}`) })))
// A closed sprint takes no new task : it is only listed for the task already inside
const sprintOptions = computed(() => [
  { value: null, label: t('tasks.backlog') },
  ...store.sprints
    .filter(({ state, id }) => state !== 'closed' || id === props.task?.sprint)
    .map(({ id, name }) => ({ value: id, label: name })),
])

watch(visible, async (shown) => {
  if (!shown) return

  error.value = null
  history.value = []
  form.value = {
    title: props.task?.title ?? '',
    description: props.task?.description ?? '',
    status: props.task?.status ?? 'todo',
    sprint: props.task ? props.task.sprint : props.sprint,
  }

  if (props.task) {
    const { data } = await listTaskHistory({ path: { task: props.task.id } })
    history.value = data ?? []
  }
})

function parse(details: string): Record<string, any> {
  try {
    return JSON.parse(details)
  } catch {
    return {}
  }
}

function lineLabel(type: string): string {
  const key = `history.type.${type.replaceAll('.', '_')}`
  return te(key) ? t(key) : type
}

async function persist(): Promise<boolean> {
  const { title, description, status, sprint } = form.value
  const body = { title: title.trim(), description }

  if (!props.task) {
    const { response, data } = await createTask({ body: { ...body, sprint } })
    if (!response?.ok || !data) return false
    return status === 'todo' || store.move(data.id, sprint, status)
  }

  const { response } = await updateTask({ path: { task: props.task.id }, body })
  if (!response?.ok) return false
  return status === props.task.status && sprint === props.task.sprint ? true : store.move(props.task.id, sprint, status)
}

async function save() {
  saving.value = true
  error.value = null

  const saved = await persist()
  await store.load()
  saving.value = false

  if (saved) visible.value = false
  else error.value = t('tasks.saveError')
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :header="t(task ? 'tasks.dialogEdit' : 'tasks.dialogCreate')" class="task-dialog" :style="{ width: 'min(36rem, calc(100vw - 2rem))' }">
    <form class="task-form" @submit.prevent="save">
      <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

      <div class="field">
        <label for="task-title">{{ t('tasks.titleLabel') }}</label>
        <InputText id="task-title" v-model="form.title" maxlength="255" required autofocus fluid />
      </div>
      <div class="field">
        <label for="task-description">{{ t('tasks.descriptionLabel') }}</label>
        <Textarea id="task-description" v-model="form.description" rows="5" fluid />
      </div>
      <div class="field-row">
        <div class="field">
          <label for="task-status">{{ t('tasks.statusLabel') }}</label>
          <Select v-model="form.status" input-id="task-status" :options="statusOptions" option-label="label" option-value="value" fluid />
        </div>
        <div class="field">
          <label for="task-sprint">{{ t('tasks.sprintLabel') }}</label>
          <Select v-model="form.sprint" input-id="task-sprint" :options="sprintOptions" option-label="label" option-value="value" fluid />
        </div>
      </div>

      <div class="actions">
        <Button type="submit" :label="t('tasks.save')" :loading="saving" />
      </div>
    </form>

    <section v-if="history.length" class="task-history">
      <h3>{{ t('tasks.historyTitle') }}</h3>
      <ul>
        <li v-for="line in history" :key="line.id">
          <strong>{{ lineLabel(line.type) }}</strong>
          <span v-if="taskChange(t, line.type, parse(line.details))">{{ taskChange(t, line.type, parse(line.details)) }}</span>
          <small>{{ line.author }} · {{ d(new Date(line.created_at), 'short') }}</small>
        </li>
      </ul>
    </section>
  </Dialog>
</template>

<style scoped>
.task-form,
.field {
  display: flex;
  flex-direction: column;
}

.task-form {
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

.actions {
  display: flex;
  justify-content: flex-end;
}

.task-history {
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px solid var(--p-content-border-color);
}

.task-history h3 {
  margin: 0 0 0.5rem;
  font-size: 0.9375rem;
  font-weight: 600;
}

.task-history ul {
  display: flex;
  flex-direction: column;
  gap: 0.625rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.task-history li {
  display: flex;
  flex-direction: column;
  font-size: 0.875rem;
}

.task-history small {
  color: var(--p-text-muted-color);
}
</style>
