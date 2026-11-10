<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import { VueDraggable } from 'vue-draggable-plus'
import TaskCard from './TaskCard.vue'
import { BOARD_STATUSES, useTasksStore, type Task, type TaskStatus } from '../stores/tasks'

const emit = defineEmits<{ open: [task: Task]; create: []; backlog: [] }>()

const { t } = useI18n()
const toast = useToast()
const store = useTasksStore()

const columns = ref<Record<string, Task[]>>({})
const showCancelled = ref(false)

const sprintTasks = computed(() => store.tasks.filter(({ sprint }) => sprint !== null && sprint === store.activeSprint?.id))
const cancelled = computed(() => sprintTasks.value.filter(({ status }) => status === 'cancelled'))

// The lists are copies : the drag library writes into them before the server has answered
watch(
  sprintTasks,
  (tasks) => {
    columns.value = Object.fromEntries(BOARD_STATUSES.map((status) => [status, tasks.filter((task) => task.status === status)]))
  },
  { immediate: true },
)

async function drop(status: TaskStatus, event: { data: Task }) {
  await nextTick()

  const sprint = store.activeSprint?.id
  if (!sprint) return

  const ordered = (columns.value[status] ?? []).map(({ id }) => id)
  if (!(await store.move(event.data.id, sprint, status, ordered))) toast.add({ severity: 'error', summary: t('tasks.moveError'), life: 5000 })
}

async function closeActive() {
  const sprint = store.activeSprint
  if (!sprint || !window.confirm(t('tasks.closeConfirm', { name: sprint.name }))) return
  if (!(await store.close(sprint.id))) toast.add({ severity: 'error', summary: t('tasks.sprintError'), life: 5000 })
}
</script>

<template>
  <div v-if="store.activeSprint" class="board-root">
    <header class="board-header">
      <h2>{{ store.activeSprint.name }}</h2>
      <label class="toggle">
        <ToggleSwitch v-model="showCancelled" />
        {{ t('tasks.showCancelled') }}
      </label>
      <Button :label="t('tasks.closeSprint')" icon="pi pi-flag" severity="secondary" outlined size="small" @click="closeActive" />
      <Button :label="t('tasks.newTask')" icon="pi pi-plus" size="small" @click="emit('create')" />
    </header>

    <div class="board">
      <section v-for="status in BOARD_STATUSES" :key="status" class="column" :data-status="status">
        <h3>{{ t(`tasks.status.${status}`) }} <span>{{ columns[status]?.length ?? 0 }}</span></h3>
        <VueDraggable
          v-model="columns[status]!"
          class="column-list"
          group="tasks-board"
          :animation="150"
          :force-fallback="true"
          filter="a, button"
          :prevent-on-filter="false"
          ghost-class="task-ghost"
          @add="drop(status, $event)"
          @update="drop(status, $event)"
        >
          <TaskCard v-for="task in columns[status]" :key="task.id" :task="task" @open="emit('open', task)" />
        </VueDraggable>
      </section>
    </div>

    <section v-if="showCancelled" class="cancelled">
      <h3>{{ t('tasks.cancelledTitle') }}</h3>
      <p v-if="!cancelled.length" class="empty">{{ t('tasks.emptyList') }}</p>
      <TaskCard v-for="task in cancelled" :key="task.id" :task="task" @open="emit('open', task)" />
    </section>
  </div>

  <div v-else class="no-sprint">
    <p>{{ t('tasks.noActiveSprint') }}</p>
    <Button :label="t('tasks.goToBacklog')" severity="secondary" outlined @click="emit('backlog')" />
  </div>
</template>

<style scoped>
.board-root {
  min-width: 0;
}

.board-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.board-header h2 {
  flex: 1 1 8rem;
  margin: 0;
  font-size: 1.125rem;
  font-weight: 600;
}

.toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
}

.board {
  display: grid;
  grid-template-columns: repeat(5, minmax(12rem, 1fr));
  gap: 0.75rem;
  overflow-x: auto;
  padding-bottom: 0.5rem;
}

.column {
  display: flex;
  flex-direction: column;
  min-width: 0;
  padding: 0.625rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.column h3,
.cancelled h3 {
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
  min-height: 6rem;
}

.cancelled {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: 24rem;
  margin-top: 1rem;
}

.empty,
.no-sprint p {
  margin: 0;
  color: var(--p-text-muted-color);
}

.no-sprint {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.75rem;
}

:deep(.task-ghost) {
  opacity: 0.4;
}
</style>
