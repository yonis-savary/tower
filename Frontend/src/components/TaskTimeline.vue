<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import EmptyState from './EmptyState.vue'
import { getTaskTimeline, listWorkedJiraIssues } from '../client'
import { JIRA_SEVERITIES, useJiraStore } from '../stores/jira'
import { useTasksStore, type Task, type TaskStatus } from '../stores/tasks'
import { useWorkspaceStore } from '../stores/workspace'
import { storedFlag } from '../utils/storedFlag'

const emit = defineEmits<{ open: [task: Task] }>()

type Category = 'todo' | 'in_progress' | 'done'
type Change = { at: string; from: string | null; to: string | null; from_category: Category; to_category: Category }
/** Where the server placed an item : waiting in a to do status is not working on it */
type Span = {
  status: string
  status_category: Category
  created_at: string | null
  started_at: string | null
  ended_at: string | null
  /** The category at the start of the period, or at the creation when it came after */
  initial_category: Category
  changes: Change[]
}
type TimelineTask = Span & { id: number; title: string; status: TaskStatus; sprint: number | null; sprint_name: string | null }
type WorkedIssue = Span & { key: string; summary: string; url: string; project: string; issue_type: string }

/** A stretch of time in one status, in percent of the period */
type Segment = { left: number; width: number; waiting: boolean; ongoing: boolean; startsBefore: boolean; endsAfter: boolean; label: string }

type Row = {
  id: string
  title: string
  caption: string | null
  status: string
  severity: string
  segments: Segment[]
  changes: { left: number; label: string }[]
  task?: TimelineTask
  url?: string
}

const DAY = 86_400_000
const MAX_DAYS = 92
const TASK_SEVERITIES: Record<TaskStatus, string> = {
  todo: 'secondary',
  in_progress: 'info',
  in_review: 'info',
  finalizing: 'warn',
  done: 'success',
  cancelled: 'secondary',
}

const { t, locale } = useI18n()
const workspace = useWorkspaceStore()
const store = useTasksStore()
const jira = useJiraStore()

const range = ref<(Date | null)[] | null>(rollingMonth())
const includeJira = storedFlag(computed(() => `tower.tasks.timelineJira.${workspace.current?.id}`))
const tasks = ref<TimelineTask[]>([])
const issues = ref<WorkedIssue[]>([])
const truncated = ref(false)
const loading = ref(false)
const failed = ref(false)
const jiraFailed = ref(false)
let request = 0

const jiraAvailable = computed(() => workspace.hasSection('jira') && jira.profiles.length > 0)
const withJira = computed(() => includeJira.value && jiraAvailable.value)

const period = computed(() => {
  const [from, to] = range.value ?? []
  if (!from || !to) return null

  const start = startOfDay(from)
  const end = startOfDay(to).getTime() + DAY - 1
  return { from: isoDay(from), to: isoDay(to), start: start.getTime(), end, days: Math.round((end + 1 - start.getTime()) / DAY) }
})

function rollingMonth(): Date[] {
  const today = startOfDay(new Date())
  const from = new Date(today)
  from.setMonth(from.getMonth() - 1)
  return [from, today]
}

function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

function isoDay(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

const maxDate = computed(() => {
  const [from, to] = range.value ?? []
  if (!from || to) return undefined
  return new Date(from.getTime() + MAX_DAYS * DAY)
})

async function load() {
  const current = period.value
  if (!current) return

  const id = ++request
  loading.value = true
  const query = { from: current.from, to: current.to }
  const [taskList, issueList] = await Promise.all([
    getTaskTimeline({ query }),
    withJira.value ? listWorkedJiraIssues({ query }) : Promise.resolve(null),
  ])
  if (id !== request) return

  failed.value = !taskList.data
  tasks.value = (taskList.data ?? []) as TimelineTask[]
  jiraFailed.value = !!issueList && !issueList.data
  const worked = issueList?.data as { issues: WorkedIssue[]; truncated: boolean } | undefined
  issues.value = worked?.issues ?? []
  truncated.value = worked?.truncated ?? false
  loading.value = false
}

watch([period, withJira, () => workspace.current?.id], load, { immediate: true })
// A task moved from the board or the dialog changes the timeline too
watch(() => store.tasks, load)

const dayFormat = computed(() => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' }))
const dateTimeFormat = computed(() => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }))

function describeChange(change: Change, translate: (status: string | null) => string): string {
  return t('tasks.timeline.change', { date: dateTimeFormat.value.format(new Date(change.at)), from: translate(change.from), to: translate(change.to) })
}

/**
 * Splits the life of an item over the period by status : waiting in a to do status draws a thin line,
 * working a bar, nothing once done
 */
function segmentsOf(item: Span, translate: (status: string | null) => string): Segment[] {
  const current = period.value
  if (!current) return []

  const span = current.end - current.start
  const at = (time: number) => ((time - current.start) / span) * 100
  const now = Math.min(Date.now(), current.end)
  const created = item.created_at ? new Date(item.created_at).getTime() : current.start

  const stretches: { from: number; to: number; status: string; category: Category }[] = []
  let cursor = Math.max(created, current.start)
  let status = item.changes[0]?.from ?? item.status
  let category = item.initial_category
  for (const change of item.changes) {
    const time = new Date(change.at).getTime()
    stretches.push({ from: cursor, to: time, status, category })
    cursor = time
    status = change.to ?? ''
    category = change.to_category
  }
  stretches.push({ from: cursor, to: now, status, category })

  return stretches
    .filter(({ from, to, category }) => category !== 'done' && to > from)
    .map(({ from, to, status, category }) => ({
      left: at(from),
      width: Math.max(at(to) - at(from), 0.5),
      waiting: category === 'todo',
      // Still being worked on : the hatching says it goes on
      ongoing: category === 'in_progress' && to >= now && item.ended_at === null,
      startsBefore: from <= current.start && created < current.start,
      endsAfter: to >= now && item.ended_at === null,
      label: t('tasks.timeline.stretch', { status: translate(status), from: dateTimeFormat.value.format(new Date(from)), to: dateTimeFormat.value.format(new Date(to)) }),
    }))
}

function changesOf(item: Span, translate: (status: string | null) => string): Row['changes'] {
  const current = period.value
  if (!current) return []

  return item.changes.map((change) => ({ left: ((new Date(change.at).getTime() - current.start) / (current.end - current.start)) * 100, label: describeChange(change, translate) }))
}

const translateTaskStatus = (status: string | null) => (status ? t(`tasks.status.${status}`) : '—')
const translateJiraStatus = (status: string | null) => status || '—'

const taskRows = computed<Row[]>(() =>
  tasks.value.map((task) => ({
    id: `task-${task.id}`,
    title: task.title,
    caption: task.sprint_name,
    status: translateTaskStatus(task.status),
    severity: TASK_SEVERITIES[task.status],
    segments: segmentsOf(task, translateTaskStatus),
    changes: changesOf(task, translateTaskStatus),
    task,
  })),
)

const issueRows = computed<Row[]>(() =>
  issues.value.map((issue) => ({
    id: `jira-${issue.key}`,
    title: `${issue.key} ${issue.summary}`,
    caption: issue.project,
    status: issue.status,
    severity: JIRA_SEVERITIES[issue.status_category] ?? 'secondary',
    segments: segmentsOf(issue, translateJiraStatus),
    changes: changesOf(issue, translateJiraStatus),
    url: issue.url,
  })),
)

const doneCount = computed(() => tasks.value.filter(({ status }) => status === 'done').length)

/** Labelled marks under the axis : every day on a short period, the mondays beyond two weeks */
const ticks = computed(() => {
  const current = period.value
  if (!current) return []

  const marks: { left: number; label: string; month: boolean }[] = []
  for (let day = new Date(current.start); day.getTime() <= current.end; day.setDate(day.getDate() + 1)) {
    const month = day.getDate() === 1
    if (current.days > 14 && day.getDay() !== 1 && !month) continue
    marks.push({ left: ((day.getTime() - current.start) / (current.end - current.start)) * 100, label: dayFormat.value.format(day), month })
  }
  return marks
})

const today = computed(() => {
  const current = period.value
  const now = Date.now()
  if (!current || now < current.start || now > current.end) return null
  return ((now - current.start) / (current.end - current.start)) * 100
})

function openRow(item: Row) {
  if (item.url) {
    window.open(item.url, '_blank', 'noopener')
    return
  }
  const task = store.tasks.find(({ id }) => id === item.task?.id)
  if (task) emit('open', task)
}

function resetPeriod() {
  range.value = rollingMonth()
}
</script>

<template>
  <header class="timeline-header">
    <div class="period">
      <label for="timeline-period" class="period-label">{{ t('tasks.timeline.period') }}</label>
      <DatePicker
        v-model="range"
        input-id="timeline-period"
        selection-mode="range"
        :manual-input="false"
        :max-date="maxDate"
        date-format="dd/mm/yy"
        show-icon
        icon-display="input"
        class="period-input"
      />
      <Button :label="t('tasks.timeline.rollingMonth')" icon="pi pi-refresh" severity="secondary" text size="small" @click="resetPeriod" />
    </div>
    <label v-if="jiraAvailable" class="toggle">
      <Checkbox v-model="includeJira" binary input-id="timeline-jira" />
      {{ t('tasks.timeline.includeJira') }}
    </label>
  </header>

  <Message v-if="failed" class="timeline-message" severity="error" :closable="false">{{ t('tasks.timeline.loadError') }}</Message>

  <section v-if="period" :class="['panel', { loading }]" :aria-busy="loading">
    <header class="section-header">
      <h2>{{ t('tasks.timeline.tasksTitle') }}</h2>
      <span class="count">{{ t('tasks.timeline.summary', { count: tasks.length }) }} · {{ t('tasks.timeline.doneCount', { count: doneCount }) }}</span>
    </header>

    <div class="chart">
      <div class="axis" aria-hidden="true">
        <span class="axis-spacer" />
        <div class="axis-track">
          <span v-for="tick in ticks" :key="tick.left" :class="['tick', { month: tick.month }]" :style="{ left: `${tick.left}%` }">{{ tick.label }}</span>
        </div>
      </div>

      <template v-for="group in [{ key: 'tasks', rows: taskRows }, ...(withJira ? [{ key: 'jira', rows: issueRows }] : [])]" :key="group.key">
        <h3 v-if="group.key === 'jira'" class="group-title">
          {{ t('tasks.timeline.jiraTitle') }}
          <span class="count">{{ issueRows.length }}</span>
        </h3>
        <Message v-if="group.key === 'jira' && jiraFailed" severity="error" size="small" :closable="false">{{ t('tasks.timeline.jiraError') }}</Message>
        <Message v-if="group.key === 'jira' && truncated" severity="warn" size="small" :closable="false">{{ t('tasks.timeline.jiraTruncated') }}</Message>

        <EmptyState
          v-if="!group.rows.length && !(group.key === 'jira' && jiraFailed)"
          compact
          :icon="group.key === 'jira' ? 'pi pi-ticket' : 'pi pi-calendar'"
          :title="t(group.key === 'jira' ? 'tasks.timeline.jiraEmptyTitle' : 'tasks.timeline.emptyTitle')"
          :hint="t(group.key === 'jira' ? 'tasks.timeline.jiraEmptyHint' : 'tasks.timeline.emptyHint')"
        />

        <ul v-else class="rows">
          <li v-for="item in group.rows" :key="item.id" class="row">
            <button type="button" class="row-label" :title="item.url ? t('tasks.timeline.openInJira') : item.title" @click="openRow(item)">
              <span class="row-title">
                {{ item.title }}
                <i v-if="item.url" class="pi pi-external-link" aria-hidden="true" />
              </span>
              <span class="row-meta">
                <Tag :value="item.status" :severity="item.severity" />
                <small v-if="item.caption">{{ item.caption }}</small>
              </span>
            </button>
            <div class="row-track">
              <span v-for="tick in ticks" :key="tick.left" :class="['grid-line', { month: tick.month }]" :style="{ left: `${tick.left}%` }" />
              <span v-if="today !== null" class="today" :style="{ left: `${today}%` }" />
              <span
                v-for="(segment, index) in item.segments"
                :key="`segment-${index}`"
                :class="['bar', `bar-${item.severity}`, { waiting: segment.waiting, ongoing: segment.ongoing, 'starts-before': segment.startsBefore, 'ends-after': segment.endsAfter }]"
                :style="{ left: `${segment.left}%`, width: `${segment.width}%` }"
                :title="segment.label"
              />
              <span v-for="(change, index) in item.changes" :key="`change-${index}`" class="change" :style="{ left: `${change.left}%` }" :title="change.label" />
            </div>
          </li>
        </ul>
      </template>
    </div>

    <p class="footnote">{{ t('tasks.timeline.historyLimit') }}</p>
  </section>
</template>

<style scoped>
.timeline-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.period {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.period-label {
  font-size: 0.875rem;
  font-weight: 600;
}

.period-input {
  width: 15rem;
  max-width: 100%;
}

.toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  cursor: pointer;
}

.timeline-message {
  margin-bottom: 1rem;
}

.panel {
  padding: 1rem var(--panel-padding);
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  transition: opacity 0.15s;
}

.panel.loading {
  opacity: 0.6;
}

.section-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
}

.section-header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.count {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
  font-weight: 400;
}

.chart {
  --label-width: minmax(10rem, 18rem);
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.axis,
.row {
  display: grid;
  grid-template-columns: var(--label-width) 1fr;
  gap: 0.75rem;
  align-items: center;
}

.axis {
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 0.25rem 0;
  background: var(--p-content-background);
  border-bottom: 1px solid var(--p-content-border-color);
}

.axis-track {
  position: relative;
  height: 1.25rem;
}

.tick {
  position: absolute;
  top: 0;
  transform: translateX(-50%);
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
  white-space: nowrap;
}

.tick.month {
  color: var(--p-text-color);
  font-weight: 600;
}

.group-title {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  margin: 0.75rem 0 0;
  font-size: 0.9375rem;
  font-weight: 600;
}

.rows {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.row-label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
  padding: 0.375rem 0.5rem;
  border: 0;
  border-radius: var(--p-content-border-radius);
  background: transparent;
  color: var(--p-text-color);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.row-label:hover,
.row-label:focus-visible {
  background: var(--p-content-hover-background);
}

.row-title {
  overflow: hidden;
  font-size: 0.875rem;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row-title .pi {
  margin-left: 0.25rem;
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
}

.row-meta {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
}

.row-meta :deep(.p-tag) {
  padding: 0 0.375rem;
  font-size: 0.6875rem;
}

.row-meta small {
  overflow: hidden;
  color: var(--p-text-muted-color);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row-track {
  position: relative;
  height: 2rem;
}

.grid-line {
  position: absolute;
  top: 0;
  bottom: 0;
  border-left: 1px dashed var(--p-content-border-color);
}

.grid-line.month {
  border-left-style: solid;
}

.today {
  position: absolute;
  top: -0.25rem;
  bottom: -0.25rem;
  border-left: 2px solid var(--p-primary-color);
  opacity: 0.6;
}

.bar {
  --bar-color: var(--p-surface-400);
  position: absolute;
  top: 50%;
  height: 0.75rem;
  transform: translateY(-50%);
  border-radius: 999px;
  background: var(--bar-color);
}

.bar-info {
  --bar-color: var(--p-sky-500);
}

.bar-warn {
  --bar-color: var(--p-orange-500);
}

.bar-success {
  --bar-color: var(--p-green-500);
}

/* Started before the period, or still going after it : the bar fades out on that side */
.bar.starts-before {
  border-top-left-radius: 0;
  border-bottom-left-radius: 0;
  mask-image: linear-gradient(to right, transparent, #000 1.5rem);
}

.bar.ends-after {
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;
  mask-image: linear-gradient(to left, transparent, #000 1.5rem);
}

.bar.starts-before.ends-after {
  mask-image: linear-gradient(to right, transparent, #000 1.5rem, #000 calc(100% - 1.5rem), transparent);
}

.bar.ongoing {
  background: repeating-linear-gradient(135deg, var(--bar-color) 0 0.5rem, color-mix(in srgb, var(--bar-color) 70%, transparent) 0.5rem 1rem);
}

/* Waiting in a to do status : not worked on yet */
.bar.waiting {
  height: 0.125rem;
  border-radius: 0;
  background: var(--p-surface-400);
}

.change {
  position: absolute;
  top: 50%;
  width: 0.625rem;
  height: 0.625rem;
  transform: translate(-50%, -50%);
  border: 2px solid var(--p-content-background);
  border-radius: 50%;
  background: var(--p-text-color);
  cursor: help;
}

.footnote {
  margin: 1rem 0 0;
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
}

/* A phone has no room for two columns : the label goes above its bar */
@media (max-width: 640px) {
  .axis,
  .row {
    grid-template-columns: 1fr;
    gap: 0.125rem;
  }

  .axis-spacer {
    display: none;
  }

  .tick:not(.month):nth-child(even) {
    display: none;
  }
}
</style>
