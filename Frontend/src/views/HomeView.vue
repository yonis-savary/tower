<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import EmptyState from '../components/EmptyState.vue'
import HomeActivity from '../components/HomeActivity.vue'
import HomeQuickActions from '../components/HomeQuickActions.vue'
import HomeTaskBoard from '../components/HomeTaskBoard.vue'
import TaskDialog from '../components/TaskDialog.vue'
import { useDeviceSocketStore, type WorkspaceSignal } from '../stores/deviceSocket'
import { useJiraStore } from '../stores/jira'
import { useTasksStore, type Task } from '../stores/tasks'
import { useWorkspaceStore } from '../stores/workspace'
import { EVENT_TYPE } from '../utils/historyLine'

// A burst of signals (a sync, a task move) reloads once
const RELOAD_DELAY_MS = 300

const { t } = useI18n()
const workspace = useWorkspaceStore()
const tasks = useTasksStore()
const jira = useJiraStore()
const socket = useDeviceSocketStore()

const activity = ref<InstanceType<typeof HomeActivity> | null>(null)
const dialogVisible = ref(false)
const dialogTask = ref<Task | null>(null)

const showTasks = computed(() => workspace.hasSection('tasks'))
const showQuickActions = computed(() => workspace.hasSection('events'))
const showActivity = computed(() => workspace.hasSection('events') || workspace.hasSection('webhooks') || workspace.hasSection('automation'))

// Each block hides itself while empty : null until it has loaded
const filled = ref<{ tasks: boolean | null; quickActions: boolean | null; activity: boolean | null }>({ tasks: null, quickActions: null, activity: null })
const isEmpty = computed(() => {
  const shown = [showTasks.value && filled.value.tasks, showQuickActions.value && filled.value.quickActions, showActivity.value && filled.value.activity]
  return !shown.includes(null) && !shown.includes(true)
})

function loadTasks() {
  if (!showTasks.value) return
  void tasks.load()
  // The cards show their Jira ticket
  if (workspace.hasSection('jira')) void jira.load()
}

watch(() => [workspace.current?.id, showTasks.value], ([id]) => { if (id) loadTasks() }, { immediate: true })

function debounced(reload: () => void): () => void {
  let timer: ReturnType<typeof setTimeout> | undefined
  return () => {
    clearTimeout(timer)
    timer = setTimeout(reload, RELOAD_DELAY_MS)
  }
}

const reloadTasks = debounced(loadTasks)
const reloadActivity = debounced(() => void activity.value?.load())

const isEvent = (type: string) => type === EVENT_TYPE || type.startsWith('pr.') || type.startsWith('jira.')
const isTaskChange = (type: string) => type.startsWith('task.') || type.startsWith('sprint.')

function onSignal(signal: WorkspaceSignal) {
  if (signal.type === 'history.created') {
    if (isTaskChange(signal.history_type)) reloadTasks()
    if (isEvent(signal.history_type)) reloadActivity()
    return
  }

  // delivery.updated, webhook.refused
  reloadActivity()
}

const stopListening = socket.onSignal(onSignal)
onBeforeUnmount(stopListening)

// The signals sent while the socket was down are lost : everything is read again
watch(() => socket.status, (status, previous) => {
  if (status !== 'connected' || previous === 'connected') return
  reloadTasks()
  reloadActivity()
})

function openTask(task: Task) {
  dialogTask.value = task
  dialogVisible.value = true
}
</script>

<template>
  <h1 class="page-title">{{ t('nav.home') }}</h1>

  <EmptyState v-if="isEmpty" icon="pi pi-home" :title="t('home.emptyTitle')" :hint="t('home.emptyHint')">
    <RouterLink v-if="showTasks" v-slot="{ navigate }" :to="{ name: 'tasks' }" custom>
      <Button :label="t('home.emptyTasks')" icon="pi pi-check-square" @click="navigate" />
    </RouterLink>
    <RouterLink v-slot="{ navigate }" :to="{ name: 'docs' }" custom>
      <Button :label="t('home.emptyDocs')" icon="pi pi-book" severity="secondary" outlined @click="navigate" />
    </RouterLink>
  </EmptyState>

  <div class="home">
    <HomeTaskBoard v-if="showTasks" v-model:filled="filled.tasks" @open="openTask" />

    <div v-if="showQuickActions || showActivity" class="home-row">
      <HomeQuickActions v-if="showQuickActions" v-model:filled="filled.quickActions" />
      <HomeActivity v-if="showActivity" ref="activity" v-model:filled="filled.activity" />
    </div>
  </div>

  <TaskDialog v-if="showTasks" v-model:visible="dialogVisible" :task="dialogTask" :sprint="null" />
</template>

<style scoped>
.page-title {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.home {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  min-width: 0;
}

.home-row {
  display: grid;
  grid-template-columns: minmax(16rem, 1fr) minmax(0, 2fr);
  align-items: start;
  gap: 1.25rem;
}

/* A single block takes the whole row */
.home-row > :only-child {
  grid-column: 1 / -1;
}

@media (max-width: 960px) {
  .home-row {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
