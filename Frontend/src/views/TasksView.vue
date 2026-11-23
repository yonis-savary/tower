<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import TaskBacklog from '../components/TaskBacklog.vue'
import TaskBoard from '../components/TaskBoard.vue'
import TaskDialog from '../components/TaskDialog.vue'
import TaskNotesDialog from '../components/TaskNotesDialog.vue'
import { useJiraStore } from '../stores/jira'
import { useTasksStore, type Task } from '../stores/tasks'
import { useWorkspaceStore } from '../stores/workspace'

const { t } = useI18n()
const workspace = useWorkspaceStore()
const store = useTasksStore()
const jira = useJiraStore()
const route = useRoute()
const router = useRouter()

const tab = ref('board')
const dialogVisible = ref(false)
const dialogTask = ref<Task | null>(null)
const dialogSprint = ref<number | null>(null)
const notesVisible = ref(false)
const notesSprint = ref<number | null>(null)

watch(() => workspace.current?.id, (id) => { if (id) void Promise.all([store.load(), jira.load()]) }, { immediate: true })

function openTask(task: Task) {
  dialogTask.value = task
  dialogVisible.value = true
}

// Opened from another screen through ?task=<id>, once the tasks are loaded
watch(
  () => [route.query.task, store.tasks] as const,
  ([id, tasks]) => {
    const task = id ? tasks.find((candidate) => candidate.id === Number(id)) : undefined
    if (!task) return

    openTask(task)
    void router.replace({ query: {} })
  },
  { immediate: true },
)

function createTask(sprint: number | null) {
  dialogTask.value = null
  dialogSprint.value = sprint
  dialogVisible.value = true
}

function takeNotes(sprint: number | null) {
  notesSprint.value = sprint
  notesVisible.value = true
}
</script>

<template>
  <h1 class="page-title">{{ t('nav.tasks') }}</h1>
  <Message v-if="store.failed" class="load-error" severity="error" :closable="false">{{ t('tasks.loadError') }}</Message>

  <Tabs v-model:value="tab" class="tasks-tabs">
    <TabList>
      <Tab value="board">{{ t('tasks.board') }}</Tab>
      <Tab value="backlog">{{ t('tasks.backlog') }}</Tab>
    </TabList>
    <TabPanels>
      <TabPanel value="board">
        <TaskBoard @open="openTask" @create="createTask" @notes="takeNotes" @backlog="tab = 'backlog'" />
      </TabPanel>
      <TabPanel value="backlog">
        <TaskBacklog @open="openTask" @create="createTask" @notes="takeNotes" />
      </TabPanel>
    </TabPanels>
  </Tabs>

  <TaskDialog v-model:visible="dialogVisible" :task="dialogTask" :sprint="dialogSprint" />
  <TaskNotesDialog v-model:visible="notesVisible" :sprint="notesSprint" />
</template>

<style scoped>
.load-error {
  margin-bottom: 1rem;
}

/* Down to the bottom of the page, so that an empty tab centers its empty state in it */
.tasks-tabs,
.tasks-tabs :deep(.p-tabpanels),
.tasks-tabs :deep(.p-tabpanel) {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.tasks-tabs :deep(.p-tabpanels) {
  padding: 1rem 0 0;
  background: transparent;
}
</style>
