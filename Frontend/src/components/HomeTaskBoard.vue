<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import MultiSelect from 'primevue/multiselect'
import { useToast } from 'primevue/usetoast'
import { VueDraggable } from 'vue-draggable-plus'
import TaskCard from './TaskCard.vue'
import { useTasksStore, type Task, type TaskStatus } from '../stores/tasks'
import { useWorkspaceStore } from '../stores/workspace'

/** Everything still to finish : done and cancelled tasks stay on the Tasks screen */
const HOME_STATUSES: TaskStatus[] = ['todo', 'in_progress', 'in_review', 'finalizing']

const emit = defineEmits<{ open: [task: Task] }>()

const { t } = useI18n()
const toast = useToast()
const store = useTasksStore()
const workspace = useWorkspaceStore()

// The hidden sprints are kept rather than the shown ones, so that a newly opened sprint shows up
const hiddenSprints = ref<number[]>([])
const hiddenSprintsKey = computed(() => `tower.home.hiddenSprints.${workspace.current?.id}`)

watch(hiddenSprintsKey, (key) => {
  try {
    hiddenSprints.value = JSON.parse(localStorage.getItem(key) ?? '[]')
  } catch {
    hiddenSprints.value = []
  }
}, { immediate: true })

const sprintOptions = computed(() => store.activeSprints.map(({ id, name }) => ({ value: id, label: name })))

const shownSprints = computed({
  get: () => store.activeSprints.map(({ id }) => id).filter((id) => !hiddenSprints.value.includes(id)),
  set: (shown: number[]) => {
    hiddenSprints.value = store.activeSprints.map(({ id }) => id).filter((id) => !shown.includes(id))
    try {
      localStorage.setItem(hiddenSprintsKey.value, JSON.stringify(hiddenSprints.value))
    } catch {
      // Private browsing or blocked storage : the choice only lasts for this page
    }
  },
})

const sprintNames = computed(() => new Map(store.activeSprints.map(({ id, name }) => [id, name])))
// Mixed sprints : each keeps its own order, the oldest sprint first
const sprintRank = computed(() => new Map(store.activeSprints.map(({ id }, rank) => [id, rank])))

const columns = ref<Record<string, Task[]>>({})

// Hidden while there is nothing to finish. Counted before the sprint filter : hiding every sprint keeps the filter at hand
const hasContent = computed(() => store.tasks.some((task) =>
  HOME_STATUSES.includes(task.status) && store.activeSprints.some(({ id }) => id === task.sprint)))

// The lists are copies : the drag library writes into them before the server has answered
watch([() => store.tasks, shownSprints], () => {
  const shown = store.tasks
    .filter((task) => task.sprint !== null && shownSprints.value.includes(task.sprint))
    .sort((a, b) => (sprintRank.value.get(a.sprint!) ?? 0) - (sprintRank.value.get(b.sprint!) ?? 0) || a.position - b.position)

  columns.value = Object.fromEntries(HOME_STATUSES.map((status) => [status, shown.filter((task) => task.status === status)]))
}, { immediate: true })

// Only the tasks of the moved one's sprint are ordered : the others belong to another list
async function drop(status: TaskStatus, event: { data: Task }) {
  await nextTick()

  const task = event.data
  const ordered = (columns.value[status] ?? []).filter(({ sprint }) => sprint === task.sprint).map(({ id }) => id)
  if (!(await store.move(task.id, task.sprint, status, ordered))) toast.add({ severity: 'error', summary: t('tasks.moveError'), life: 5000 })
}
</script>

<template>
  <section v-if="hasContent" class="panel">
    <header class="panel-header">
      <h2>{{ t('home.tasksTitle') }}</h2>
      <MultiSelect
        v-if="sprintOptions.length > 1"
        v-model="shownSprints"
        :options="sprintOptions"
        option-label="label"
        option-value="value"
        :placeholder="t('home.noSprintShown')"
        :max-selected-labels="2"
        :selected-items-label="t('home.sprintsShown', { count: shownSprints.length })"
        size="small"
        class="sprint-filter"
      />
    </header>

    <div class="board">
      <section v-for="status in HOME_STATUSES" :key="status" class="column" :data-status="status">
        <h3>{{ t(`tasks.status.${status}`) }} <span>{{ columns[status]?.length ?? 0 }}</span></h3>
        <VueDraggable
          v-model="columns[status]!"
          class="column-list"
          group="home-board"
          :animation="150"
          :force-fallback="true"
          filter="a, button"
          :prevent-on-filter="false"
          ghost-class="task-ghost"
          @add="drop(status, $event)"
          @update="drop(status, $event)"
        >
          <TaskCard
            v-for="task in columns[status]"
            :key="task.id"
            :task="task"
            :sprint-name="shownSprints.length > 1 ? sprintNames.get(task.sprint!) : undefined"
            @open="emit('open', task)"
          />
        </VueDraggable>
      </section>
    </div>
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
  flex-wrap: wrap;
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

.sprint-filter {
  min-width: 12rem;
  max-width: 100%;
}

.board {
  display: grid;
  grid-template-columns: repeat(4, minmax(12rem, 1fr));
  gap: 0.75rem;
  overflow-x: auto;
  padding-bottom: 0.25rem;
}

.column {
  display: flex;
  flex-direction: column;
  min-width: 0;
  padding: 0.625rem;
  background: var(--p-content-hover-background);
  border-radius: var(--p-content-border-radius);
}

.column h3 {
  margin: 0 0 0.625rem;
  font-size: 0.875rem;
  font-weight: 600;
}

.column h3 span {
  color: var(--p-text-muted-color);
  font-weight: 400;
}

.column-list {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-height: 4rem;
}

:deep(.task-ghost) {
  opacity: 0.4;
}
</style>
