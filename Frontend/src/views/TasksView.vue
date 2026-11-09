<script setup lang="ts">
import { ref, watch } from 'vue'
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
import { useTasksStore, type Task } from '../stores/tasks'
import { useWorkspaceStore } from '../stores/workspace'

const { t } = useI18n()
const workspace = useWorkspaceStore()
const store = useTasksStore()

const tab = ref('board')
const dialogVisible = ref(false)
const dialogTask = ref<Task | null>(null)
const dialogSprint = ref<number | null>(null)

watch(() => workspace.current?.id, (id) => { if (id) void store.load() }, { immediate: true })

function openTask(task: Task) {
  dialogTask.value = task
  dialogVisible.value = true
}

function createTask(sprint: number | null) {
  dialogTask.value = null
  dialogSprint.value = sprint
  dialogVisible.value = true
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
        <TaskBoard @open="openTask" @create="createTask(store.activeSprint?.id ?? null)" @backlog="tab = 'backlog'" />
      </TabPanel>
      <TabPanel value="backlog">
        <TaskBacklog @open="openTask" @create="createTask" />
      </TabPanel>
    </TabPanels>
  </Tabs>

  <TaskDialog v-model:visible="dialogVisible" :task="dialogTask" :sprint="dialogSprint" />
</template>

<style scoped>
.page-title {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.load-error {
  margin-bottom: 1rem;
}

.tasks-tabs {
  min-width: 0;
}

.tasks-tabs :deep(.p-tabpanels) {
  padding: 1rem 0 0;
  background: transparent;
}
</style>
