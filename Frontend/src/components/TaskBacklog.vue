<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import { VueDraggable } from 'vue-draggable-plus'
import { TOUCH_DRAG } from '../utils/touchDrag'
import SprintLinks from './SprintLinks.vue'
import TaskCard from './TaskCard.vue'
import { useTasksStore, type Sprint, type Task } from '../stores/tasks'

const emit = defineEmits<{ open: [task: Task]; create: [sprint: number | null]; notes: [sprint: number | null] }>()

type Section = { sprint: Sprint | null; tasks: Task[] }

// A closed sprint can only give its tasks away
const CLOSED_SPRINT_GROUP = { name: 'tasks-backlog', put: false }

const { t } = useI18n()
const toast = useToast()
const store = useTasksStore()

const showCancelled = ref(false)
const showClosed = ref(false)
const sections = ref<Section[]>([])
const closedSections = ref<Section[]>([])

const stateLabels: Record<string, string> = { active: 'tasks.sprintActive', planned: 'tasks.sprintPlanned', closed: 'tasks.sprintClosed' }

const openSprints = computed(() => store.sprints.filter(({ state }) => state !== 'closed'))
const closedSprints = computed(() => store.sprints.filter(({ state }) => state === 'closed').reverse())

function section(sprint: Sprint | null): Section {
  const id = sprint?.id ?? null
  return { sprint, tasks: store.tasks.filter((task) => task.sprint === id && (showCancelled.value || task.status !== 'cancelled')) }
}

// The lists are copies : the drag library writes into them before the server has answered
watch(
  [() => store.tasks, () => store.sprints, showCancelled],
  () => {
    sections.value = [...openSprints.value.map(section), section(null)]
    closedSections.value = closedSprints.value.map(section)
  },
  { immediate: true },
)

// vue-draggable-plus keeps the handlers of its first render : the section is looked up again, by its sprint
async function drop(sprint: number | null, event: { data: Task }) {
  await nextTick()

  const ordered = sections.value.find((item) => (item.sprint?.id ?? null) === sprint)!.tasks.map(({ id }) => id)
  if (!(await store.move(event.data.id, sprint, event.data.status, ordered))) toast.add({ severity: 'error', summary: t('tasks.moveError'), life: 5000 })
}

async function addSprint() {
  const name = window.prompt(t('tasks.sprintNamePrompt'), t('tasks.defaultSprintName', { number: store.sprints.length + 1 }))?.trim()
  if (name && !(await store.addSprint(name))) notifySprintError()
}

async function open(sprint: Sprint) {
  if (!(await store.open(sprint.id))) notifySprintError()
}

async function close(sprint: Sprint) {
  if (!window.confirm(t('tasks.closeConfirm', { name: sprint.name }))) return
  if (!(await store.close(sprint.id))) notifySprintError()
}

function notifySprintError() {
  toast.add({ severity: 'error', summary: t('tasks.sprintError'), life: 5000 })
}
</script>

<template>
  <header class="backlog-header">
    <label class="toggle">
      <ToggleSwitch v-model="showCancelled" />
      {{ t('tasks.showCancelled') }}
    </label>
    <Button :label="t('tasks.newSprint')" icon="pi pi-plus" severity="secondary" outlined size="small" @click="addSprint" />
  </header>

  <section v-for="item in sections" :key="item.sprint?.id ?? 'backlog'" class="panel" :data-section="item.sprint?.name ?? 'backlog'">
    <header class="section-header">
      <h2>{{ item.sprint?.name ?? t('tasks.backlog') }}</h2>
      <Tag v-if="item.sprint" :value="t(stateLabels[item.sprint.state] ?? '')" :severity="item.sprint.state === 'active' ? 'success' : 'secondary'" />
      <span class="count">{{ item.tasks.length }}</span>
      <span class="section-actions">
        <Button v-if="item.sprint?.state === 'planned'" :label="t('tasks.openSprint')" icon="pi pi-play" severity="secondary" text size="small" @click="open(item.sprint)" />
        <Button v-if="item.sprint?.state === 'active'" :label="t('tasks.closeSprint')" icon="pi pi-flag" severity="secondary" text size="small" @click="close(item.sprint)" />
        <Button icon="pi pi-list" severity="secondary" text size="small" :aria-label="t('tasks.notes.open')" :title="t('tasks.notes.shortcut')" aria-keyshortcuts="N" @click="emit('notes', item.sprint?.id ?? null)" />
        <Button icon="pi pi-plus" severity="secondary" text size="small" :aria-label="t('tasks.newTask')" :title="t('tasks.newTask')" @click="emit('create', item.sprint?.id ?? null)" />
      </span>
    </header>
    <VueDraggable
      v-model="item.tasks"
      class="task-list"
      group="tasks-backlog"
      :animation="150"
      :force-fallback="true" v-bind="TOUCH_DRAG"
      filter="a, button"
      :prevent-on-filter="false"
      ghost-class="task-ghost"
      @add="drop(item.sprint?.id ?? null, $event)"
      @update="drop(item.sprint?.id ?? null, $event)"
    >
      <TaskCard v-for="task in item.tasks" :key="task.id" :task="task" show-status @open="emit('open', task)" />
    </VueDraggable>
    <SprintLinks v-if="item.sprint" class="section-links" :links="item.sprint.links" :sprint="item.sprint.id" />
  </section>

  <section v-if="closedSections.length" class="panel">
    <button type="button" class="closed-toggle" :aria-expanded="showClosed" @click="showClosed = !showClosed">
      <i :class="['pi', showClosed ? 'pi-chevron-down' : 'pi-chevron-right']" aria-hidden="true" />
      {{ t('tasks.closedSprints') }} ({{ closedSections.length }})
    </button>

    <div v-if="showClosed" class="closed-list">
      <div v-for="item in closedSections" :key="item.sprint!.id" :data-section="item.sprint!.name">
        <header class="section-header">
          <h2>{{ item.sprint!.name }}</h2>
          <span class="count">{{ item.tasks.length }}</span>
        </header>
        <VueDraggable
          v-model="item.tasks"
          class="task-list"
          :group="CLOSED_SPRINT_GROUP"
          :sort="false"
          :animation="150"
          :force-fallback="true" v-bind="TOUCH_DRAG"
          filter="a, button"
          :prevent-on-filter="false"
          ghost-class="task-ghost"
        >
          <TaskCard v-for="task in item.tasks" :key="task.id" :task="task" show-status @open="emit('open', task)" />
        </VueDraggable>
        <SprintLinks class="section-links" :links="item.sprint!.links" />
      </div>
    </div>
  </section>
</template>

<style scoped>
.backlog-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.75rem;
  max-width: 48rem;
  margin-bottom: 1rem;
}

.toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
}

.panel {
  max-width: 48rem;
  margin-bottom: 1rem;
  padding: 1rem var(--panel-padding);
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.section-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.625rem;
}

.section-header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.count {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.section-actions {
  display: flex;
  align-items: center;
  margin-left: auto;
}

.section-links {
  margin-top: 0.625rem;
}

.task-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-height: 2.5rem;
}

.closed-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--p-text-color);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.closed-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-top: 1rem;
}

:deep(.task-ghost) {
  opacity: 0.4;
}
</style>
