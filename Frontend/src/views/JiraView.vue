<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import MultiSelect from 'primevue/multiselect'
import { useToast } from 'primevue/usetoast'
import { VueDraggable } from 'vue-draggable-plus'
import EmptyState from '../components/EmptyState.vue'
import JiraIssueCard from '../components/JiraIssueCard.vue'
import { syncJiraIntegration } from '../client'
import { useJiraStore, type JiraColumn, type JiraIssue, type JiraTransition } from '../stores/jira'
import { useTasksStore } from '../stores/tasks'
import { useWorkspaceStore } from '../stores/workspace'
import { storedFlag } from '../utils/storedFlag'

type Refusal = { issue: JiraIssue; message: string }
type Choice = { issue: JiraIssue; transitions: JiraTransition[] }
type SprintOption = { value: string; label: string }

const NO_SPRINT = 'none'
const SPRINT_STATE_ORDER: Record<string, number> = { active: 0, future: 1 }

const { t } = useI18n()
const toast = useToast()
const router = useRouter()
const workspace = useWorkspaceStore()
const store = useJiraStore()
const tasks = useTasksStore()

const loaded = ref(false)
const syncing = ref(false)
const moving = ref(false)
const creatingTask = ref<number | null>(null)
const discussing = ref<number | null>(null)
const refusal = ref<Refusal | null>(null)
const choice = ref<Choice | null>(null)
const lists = ref<JiraIssue[][]>([])
const unmapped = ref<JiraIssue[]>([])

const taskOf = computed(() => new Map(tasks.tasks.filter(({ jira_issue }) => jira_issue !== null).map(({ id, jira_issue }) => [jira_issue!, id])))

const onlyMine = storedFlag(computed(() => `tower.jira.onlyMine.${workspace.current?.id}`))
const showSubtasks = storedFlag(computed(() => `tower.jira.showSubtasks.${workspace.current?.id}`))

function subtasksOf(issue: JiraIssue): JiraIssue[] {
  return showSubtasks.value ? store.subtasksOf.get(issue.id) ?? [] : []
}

const boardIssues = computed(() => (onlyMine.value ? store.mine : store.tracked))

// The hidden sprints are kept rather than the shown ones, so that a new sprint shows up
const hiddenSprints = ref<string[]>([])
const hiddenSprintsKey = computed(() => `tower.jira.hiddenSprints.${workspace.current?.id}`)

function sprintOf(issue: JiraIssue): string {
  return issue.sprint_id ?? NO_SPRINT
}

const sprintOptions = computed<SprintOption[]>(() => {
  const sprints = new Map<string, JiraIssue>()
  boardIssues.value.forEach((issue) => { if (issue.sprint_id) sprints.set(issue.sprint_id, issue) })

  const ordered = [...sprints.values()].sort((a, b) =>
    (SPRINT_STATE_ORDER[a.sprint_state ?? ''] ?? 2) - (SPRINT_STATE_ORDER[b.sprint_state ?? ''] ?? 2) || Number(a.sprint_id) - Number(b.sprint_id))

  return [
    ...ordered.map(({ sprint_id, sprint_name }) => ({ value: sprint_id!, label: sprint_name ?? sprint_id! })),
    ...(boardIssues.value.some(({ sprint_id }) => !sprint_id) ? [{ value: NO_SPRINT, label: t('jira.noSprint') }] : []),
  ]
})

const shownSprints = computed({
  get: () => sprintOptions.value.map(({ value }) => value).filter((value) => !hiddenSprints.value.includes(value)),
  set: (shown: string[]) => {
    hiddenSprints.value = sprintOptions.value.map(({ value }) => value).filter((value) => !shown.includes(value))
    try {
      localStorage.setItem(hiddenSprintsKey.value, JSON.stringify(hiddenSprints.value))
    } catch {
      // Private browsing or blocked storage : the choice only lasts for this page
    }
  },
})

watch(hiddenSprintsKey, (key) => {
  try {
    hiddenSprints.value = JSON.parse(localStorage.getItem(key) ?? '[]')
  } catch {
    hiddenSprints.value = []
  }
}, { immediate: true })

const shownIssues = computed(() => boardIssues.value.filter((issue) => !hiddenSprints.value.includes(sprintOf(issue))))

async function load() {
  await Promise.all([store.load(), tasks.load()])
  loaded.value = true
}

watch(() => workspace.current?.id, (id) => { if (id) void load() }, { immediate: true })

// The lists are copies : the drag library writes into them before Jira has answered
watch(
  () => [shownIssues.value, store.columns] as const,
  ([issues, columns]) => {
    lists.value = columns.map(({ statuses }) => issues.filter(({ status_id }) => statuses.includes(status_id)))
    // A status the board maps to no column : Jira hides these issues, Tower keeps them visible
    const mapped = new Set(columns.flatMap(({ statuses }) => statuses))
    unmapped.value = issues.filter(({ status_id }) => !mapped.has(status_id))
  },
  { immediate: true },
)

async function synchronize() {
  syncing.value = true
  const { data } = await syncJiraIntegration()
  await store.load()
  syncing.value = false

  if (!data) toast.add({ severity: 'error', summary: t('jira.syncError'), life: 5000 })
  else if (data.last_error) toast.add({ severity: 'warn', summary: data.last_error, life: 8000 })
}

async function drop(column: JiraColumn, event: { data: JiraIssue }) {
  const issue = event.data
  refusal.value = null
  moving.value = true

  const transitions = await store.transitionsOf(issue.id)
  if (!transitions) {
    await cancelMove(t('jira.transitionsError'))
    return
  }

  const candidates = transitions.filter(({ status_id }) => column.statuses.includes(status_id))
  if (!candidates.length) {
    refusal.value = { issue, message: t('jira.noTransition') }
    await cancelMove()
    return
  }
  if (candidates.length > 1) {
    choice.value = { issue, transitions: candidates }
    return
  }

  await apply(issue, candidates[0]!)
}

async function apply(issue: JiraIssue, transition: JiraTransition) {
  choice.value = null
  moving.value = true

  const message = await store.transition(issue.id, transition.id)
  moving.value = false
  if (message !== null) refusal.value = { issue, message: message || t('jira.transitionError') }
}

async function cancelMove(error?: string) {
  choice.value = null
  // Reloading puts the dragged card back in its column
  await store.load()
  moving.value = false
  if (error) toast.add({ severity: 'error', summary: error, life: 5000 })
}

async function createTask(issue: JiraIssue) {
  creatingTask.value = issue.id
  const created = await store.createTask(issue.id)
  await tasks.load()
  creatingTask.value = null

  toast.add(created
    ? { severity: 'success', summary: t('jira.taskCreated', { key: issue.key }), life: 4000 }
    : { severity: 'error', summary: t('jira.taskError'), life: 5000 })
}

function openTask(issue: JiraIssue) {
  const task = taskOf.value.get(issue.id)
  if (task !== undefined) void router.push({ name: 'tasks', query: { task } })
}

async function discuss(issue: JiraIssue) {
  discussing.value = issue.id
  const sent = await store.discuss(issue.id)
  discussing.value = null

  toast.add(sent
    ? { severity: 'success', summary: t('jira.discussSent', { key: issue.key }), life: 4000 }
    : { severity: 'error', summary: t('jira.discussError'), life: 5000 })
}
</script>

<template>
  <header class="page-header">
    <h1 class="page-title">{{ t('nav.jira') }}</h1>
    <label class="board-option">
      <Checkbox v-model="onlyMine" binary />
      {{ t('jira.onlyMine') }}
    </label>
    <label class="board-option">
      <Checkbox v-model="showSubtasks" binary />
      {{ t('jira.subtasks') }}
    </label>
    <MultiSelect
      v-if="sprintOptions.length"
      v-model="shownSprints"
      :options="sprintOptions"
      option-label="label"
      option-value="value"
      :placeholder="t('jira.noSprintShown')"
      :selected-items-label="t('jira.sprintsShown', { count: '{0}' })"
      :max-selected-labels="2"
      :aria-label="t('jira.sprints')"
      class="sprint-select"
      size="small"
    />
    <Button :label="t('jira.sync')" icon="pi pi-refresh" severity="secondary" size="small" :loading="syncing" @click="synchronize" />
  </header>

  <Message v-if="store.failed" class="page-message" severity="error" :closable="false">{{ t('jira.loadError') }}</Message>
  <Message v-if="refusal" class="page-message" severity="warn" @close="refusal = null">
    {{ t('jira.refused', { key: refusal.issue.key }) }} {{ refusal.message }}
    <a :href="refusal.issue.url" target="_blank" rel="noopener noreferrer">{{ t('jira.openInJira') }}</a>
  </Message>

  <EmptyState v-if="loaded && !store.columns.length" icon="pi pi-objects-column" :title="t('jira.emptyTitle')" :hint="t('jira.emptyHint')">
    <RouterLink v-slot="{ navigate }" :to="{ name: 'settings', query: { tab: 'jira' } }" custom>
      <Button :label="t('jira.emptyAction')" icon="pi pi-cog" @click="navigate" />
    </RouterLink>
  </EmptyState>

  <div v-else class="board" :style="{ '--columns': store.columns.length + (unmapped.length ? 1 : 0) }" :aria-busy="moving">
    <section v-for="(column, index) in store.columns" :key="column.name" class="column">
      <h2>{{ column.name }} <span>{{ lists[index]?.length ?? 0 }}</span></h2>
      <VueDraggable
        v-model="lists[index]!"
        class="column-list"
        group="jira-board"
        :animation="150"
        :force-fallback="true"
        :disabled="moving"
        :sort="false"
        filter="a, button"
        :prevent-on-filter="false"
        ghost-class="issue-ghost"
        @add="drop(column, $event)"
      >
        <JiraIssueCard
          v-for="issue in lists[index]"
          :key="issue.id"
          :issue="issue"
          :subtasks="subtasksOf(issue)"
          :task-id="taskOf.get(issue.id) ?? null"
          :show-assignee="!onlyMine"
          :creating-task="creatingTask === issue.id"
          :discussing="discussing === issue.id"
          @create-task="createTask(issue)"
          @open-task="openTask(issue)"
          @discuss="discuss(issue)"
        />
      </VueDraggable>
    </section>

    <section v-if="unmapped.length" class="column">
      <h2>{{ t('jira.unmapped') }} <span>{{ unmapped.length }}</span></h2>
      <VueDraggable
        v-model="unmapped"
        class="column-list"
        :group="{ name: 'jira-board', put: false }"
        :animation="150"
        :force-fallback="true"
        :disabled="moving"
        :sort="false"
        filter="a, button"
        :prevent-on-filter="false"
        ghost-class="issue-ghost"
      >
        <JiraIssueCard
          v-for="issue in unmapped"
          :key="issue.id"
          :issue="issue"
          :subtasks="subtasksOf(issue)"
          :task-id="taskOf.get(issue.id) ?? null"
          :show-assignee="!onlyMine"
          :creating-task="creatingTask === issue.id"
          :discussing="discussing === issue.id"
          @create-task="createTask(issue)"
          @open-task="openTask(issue)"
          @discuss="discuss(issue)"
        />
      </VueDraggable>
    </section>
  </div>

  <Dialog
    :visible="!!choice"
    modal
    :header="t('jira.chooseStatus', { key: choice?.issue.key })"
    :style="{ width: 'min(24rem, calc(100vw - 2rem))' }"
    @update:visible="(shown) => { if (!shown) void cancelMove() }"
  >
    <div v-if="choice" class="choices">
      <Button
        v-for="transition in choice.transitions"
        :key="transition.id"
        :label="transition.status"
        severity="secondary"
        outlined
        fluid
        :title="transition.name"
        @click="apply(choice.issue, transition)"
      />
    </div>
  </Dialog>
</template>

<style scoped>
.page-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}

.page-title {
  flex: 1 1 auto;
  margin: 0;
  font-size: 1.5rem;
  font-weight: 600;
}

.board-option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
}

.sprint-select {
  width: min(18rem, 100%);
}

.page-message {
  margin-bottom: 1rem;
}

.board {
  display: grid;
  grid-template-columns: repeat(var(--columns), minmax(14rem, 1fr));
  gap: 0.75rem;
  overflow-x: auto;
  padding-bottom: 0.5rem;
}

.board[aria-busy='true'] {
  opacity: 0.7;
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

.column h2 {
  margin: 0 0 0.625rem;
  font-size: 0.875rem;
  font-weight: 600;
}

.column h2 span {
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

.choices {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

:deep(.issue-ghost) {
  opacity: 0.4;
}
</style>
