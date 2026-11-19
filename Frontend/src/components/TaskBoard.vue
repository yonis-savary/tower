<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import { VueDraggable } from 'vue-draggable-plus'
import { TOUCH_DRAG } from '../utils/touchDrag'
import SprintLinks from './SprintLinks.vue'
import TaskCard from './TaskCard.vue'
import EmptyState from './EmptyState.vue'
import { BOARD_STATUSES, useTasksStore, type Sprint, type Task, type TaskStatus } from '../stores/tasks'
import { useWorkspaceStore } from '../stores/workspace'

const emit = defineEmits<{ open: [task: Task]; create: [sprint: number]; backlog: [] }>()

type Board = { sprint: Sprint; columns: Record<string, Task[]>; cancelled: Task[]; count: number }

const { t } = useI18n()
const toast = useToast()
const store = useTasksStore()
const workspace = useWorkspaceStore()

const boards = ref<Board[]>([])
const showCancelled = ref(false)
const collapsed = ref<number[]>([])
const collapsedKey = computed(() => `tower.tasks.collapsedSprints.${workspace.current?.id}`)

function board(sprint: Sprint): Board {
  const tasks = store.tasks.filter((task) => task.sprint === sprint.id)
  const columns = Object.fromEntries(BOARD_STATUSES.map((status) => [status, tasks.filter((task) => task.status === status)]))
  const cancelled = tasks.filter(({ status }) => status === 'cancelled')

  return { sprint, columns, cancelled, count: tasks.length - cancelled.length }
}

// The lists are copies : the drag library writes into them before the server has answered
watch([() => store.tasks, () => store.activeSprints], () => { boards.value = store.activeSprints.map(board) }, { immediate: true })

watch(collapsedKey, (key) => {
  try {
    collapsed.value = JSON.parse(localStorage.getItem(key) ?? '[]')
  } catch {
    collapsed.value = []
  }
}, { immediate: true })

function toggle(sprint: Sprint) {
  collapsed.value = collapsed.value.includes(sprint.id) ? collapsed.value.filter((id) => id !== sprint.id) : [...collapsed.value, sprint.id]
  try {
    localStorage.setItem(collapsedKey.value, JSON.stringify(collapsed.value))
  } catch {
    // Private browsing or blocked storage : the choice only lasts for this page
  }
}

// vue-draggable-plus keeps the handlers of its first render : the board is looked up again, by its sprint
async function drop(sprint: number, status: TaskStatus, event: { data: Task }) {
  await nextTick()

  const ordered = (boards.value.find((item) => item.sprint.id === sprint)?.columns[status] ?? []).map(({ id }) => id)
  if (!(await store.move(event.data.id, sprint, status, ordered))) toast.add({ severity: 'error', summary: t('tasks.moveError'), life: 5000 })
}

async function close(sprint: Sprint) {
  if (!window.confirm(t('tasks.closeConfirm', { name: sprint.name }))) return
  if (!(await store.close(sprint.id))) toast.add({ severity: 'error', summary: t('tasks.sprintError'), life: 5000 })
}
</script>

<template>
  <div v-if="boards.length" class="board-root">
    <header class="board-root-header">
      <label class="toggle">
        <ToggleSwitch v-model="showCancelled" />
        {{ t('tasks.showCancelled') }}
      </label>
    </header>

    <section v-for="item in boards" :key="item.sprint.id" class="sprint" :data-sprint="item.sprint.name">
      <header class="board-header">
        <button type="button" class="sprint-toggle" :aria-expanded="!collapsed.includes(item.sprint.id)" @click="toggle(item.sprint)">
          <i :class="['pi', collapsed.includes(item.sprint.id) ? 'pi-chevron-right' : 'pi-chevron-down']" aria-hidden="true" />
          <h2>{{ item.sprint.name }}</h2>
          <span class="count">{{ item.count }}</span>
        </button>
        <Button :label="t('tasks.closeSprint')" icon="pi pi-flag" severity="secondary" outlined size="small" @click="close(item.sprint)" />
        <Button :label="t('tasks.newTask')" icon="pi pi-plus" size="small" @click="emit('create', item.sprint.id)" />
      </header>

      <template v-if="!collapsed.includes(item.sprint.id)">
        <div class="board">
          <section v-for="status in BOARD_STATUSES" :key="status" class="column" :data-status="status">
            <h3>{{ t(`tasks.status.${status}`) }} <span>{{ item.columns[status]?.length ?? 0 }}</span></h3>
            <VueDraggable
              v-model="item.columns[status]!"
              class="column-list"
              group="tasks-board"
              :animation="150"
              :force-fallback="true" v-bind="TOUCH_DRAG"
              filter="a, button"
              :prevent-on-filter="false"
              ghost-class="task-ghost"
              @add="drop(item.sprint.id, status, $event)"
              @update="drop(item.sprint.id, status, $event)"
            >
              <TaskCard v-for="task in item.columns[status]" :key="task.id" :task="task" @open="emit('open', task)" />
            </VueDraggable>
          </section>
        </div>

        <section v-if="showCancelled" class="cancelled">
          <h3>{{ t('tasks.cancelledTitle') }}</h3>
          <p v-if="!item.cancelled.length" class="empty">{{ t('tasks.emptyList') }}</p>
          <TaskCard v-for="task in item.cancelled" :key="task.id" :task="task" @open="emit('open', task)" />
        </section>

        <SprintLinks class="board-links" :links="item.sprint.links" />
      </template>
    </section>
  </div>

  <EmptyState v-else icon="pi pi-calendar" :title="t('tasks.noActiveSprintTitle')" :hint="t('tasks.noActiveSprintHint')">
    <Button :label="t('tasks.goToBacklog')" icon="pi pi-arrow-right" @click="emit('backlog')" />
  </EmptyState>
</template>

<style scoped>
.board-root {
  min-width: 0;
}

.board-root-header {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 0.5rem;
}

.board-links {
  margin-top: 0.75rem;
}

.sprint + .sprint {
  margin-top: 1.5rem;
}

.board-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.sprint-toggle {
  flex: 1 1 8rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  padding: 0;
  color: inherit;
  background: none;
  border: 0;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.sprint-toggle:focus-visible {
  outline: 2px solid var(--p-primary-color);
  border-radius: var(--p-content-border-radius);
}

.sprint-toggle .pi {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.sprint-toggle h2 {
  margin: 0;
  overflow-wrap: anywhere;
  font-size: 1.125rem;
  font-weight: 600;
}

.count {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
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
:deep(.task-ghost) {
  opacity: 0.4;
}

/* A phone : one column at a time, snapped while swiping, the next one peeking from the edge of the screen */
@media (max-width: 48rem) {
  .board {
    grid-template-columns: repeat(5, min(17.5rem, 82vw));
    margin-inline: calc(-1 * var(--page-gutter));
    padding-inline: var(--page-gutter);
    scroll-padding-inline: var(--page-gutter);
    scroll-snap-type: x mandatory;
    overscroll-behavior-x: contain;
    /* The next column peeking from the edge says there is more */
    scrollbar-width: none;
  }

  .column {
    scroll-snap-align: start;
  }
}
</style>
