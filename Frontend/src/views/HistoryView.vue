<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import {
  listEventDeliveries,
  listHistory,
  type ListEventDeliveriesResponse,
  type ListHistoryResponse,
} from '../client'
import { useWorkspaceStore } from '../stores/workspace'
import { taskChange } from '../utils/taskChange'

type Line = ListHistoryResponse[number] & { parsed: Record<string, any> }

const PAGE_SIZE = 50
const EVENT_TYPE = 'event.triggered'

const icons: Record<string, string> = {
  'device.connected': 'pi-link',
  'device.disconnected': 'pi-times-circle',
  [EVENT_TYPE]: 'pi-bolt',
  'key.created': 'pi-key',
  'key.revoked': 'pi-ban',
  'pr.opened': 'pi-share-alt',
  'pr.merged': 'pi-check-circle',
  'pr.declined': 'pi-times-circle',
  'pr.pipeline.failed': 'pi-times',
  'pr.pipeline.succeeded': 'pi-check',
  'pr.review.approved': 'pi-thumbs-up',
  'pr.review.changes_requested': 'pi-thumbs-down',
  'pr.commented': 'pi-comment',
  'pr.pushed': 'pi-upload',
  'pr.ready': 'pi-eye',
  'pr.retargeted': 'pi-directions',
  'jira.issue.assigned': 'pi-ticket',
  'jira.issue.done': 'pi-check-square',
  'jira.issue.unassigned': 'pi-user-minus',
  'jira.issue.status_changed': 'pi-arrow-right',
  'jira.issue.sprint_changed': 'pi-calendar',
  'jira.issue.priority_changed': 'pi-sort-amount-up',
  'jira.issue.updated': 'pi-pencil',
  'task.created': 'pi-plus',
  'task.updated': 'pi-pencil',
  'task.status_changed': 'pi-arrow-right',
  'task.sprint_changed': 'pi-calendar',
  'sprint.created': 'pi-calendar-plus',
  'sprint.opened': 'pi-play',
  'sprint.closed': 'pi-flag',
}

const deliverySeverity: Record<string, string> = { succeeded: 'success', received: 'success', failed: 'danger', running: 'info', pending: 'warn' }

const { t, te, d } = useI18n()
const workspace = useWorkspaceStore()

const lines = ref<Line[]>([])
const hasMore = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const openLine = ref<number | null>(null)
const deliveries = ref<ListEventDeliveriesResponse | null>(null)

function parse(details: string): Record<string, any> {
  try {
    return JSON.parse(details)
  } catch {
    return {}
  }
}

async function load(reset: boolean) {
  loading.value = true
  error.value = null

  const before = reset ? undefined : lines.value.at(-1)?.id
  const { data } = await listHistory({ query: { before } })
  loading.value = false

  if (!data) {
    error.value = t('history.loadError')
    return
  }

  const page = data.map((line) => ({ ...line, parsed: parse(line.details) }))
  lines.value = reset ? page : [...lines.value, ...page]
  hasMore.value = data.length === PAGE_SIZE
}

watch(
  () => workspace.current?.id,
  (id) => {
    openLine.value = null
    if (id) void load(true)
  },
  { immediate: true },
)

// The lines sent to the devices : the others have no delivery
function isSent(line: Line): boolean {
  return [EVENT_TYPE, 'task.', 'sprint.', 'pr.', 'jira.issue.'].some((prefix) => line.type.startsWith(prefix))
}

function label(line: Line): string {
  // A dot in a message key would read as one more level
  const key = `history.type.${line.type.replaceAll('.', '_')}`
  return te(key) ? t(key) : line.type
}

function detail(line: Line): string {
  const { parsed } = line

  if (line.type.startsWith('pr.')) return `${parsed.title} · ${parsed.repository}${integrationChange(line.type, parsed)}`
  if (line.type.startsWith('jira.issue.')) return `${parsed.key} · ${parsed.summary}${integrationChange(line.type, parsed)}`

  if (line.type.startsWith('task.')) {
    const change = taskChange(t, line.type, parsed)
    return change ? `${parsed.title} · ${change}` : parsed.title
  }

  switch (line.type) {
    case EVENT_TYPE:
      return parsed.event
    case 'device.connected':
      return `${parsed.hostname ?? t('devices.unknownHost')} · ${parsed.access_key}`
    case 'device.disconnected': {
      const reason = `history.reason.${parsed.reason}`
      return `${parsed.hostname ?? t('devices.unknownHost')} · ${te(reason) ? t(reason) : parsed.reason}`
    }
    default:
      return parsed.name ?? ''
  }
}

const transition = (parsed: Record<string, any>) => ` · ${parsed.from ?? '∅'} → ${parsed.to ?? '∅'}`
const outcome = (parsed: Record<string, any>) => (parsed.state ?? parsed.status ? ` · ${parsed.state ?? parsed.status}` : '')

const integrationChanges: Record<string, (parsed: Record<string, any>) => string> = {
  'pr.declined': outcome,
  'pr.review.approved': (parsed) => ` · ${parsed.reviewer}`,
  'pr.review.changes_requested': (parsed) => ` · ${parsed.reviewer}`,
  'pr.commented': (parsed) => ` · +${parsed.count}`,
  'pr.pushed': (parsed) => ` · ${parsed.branch} ${String(parsed.to).slice(0, 7)}`,
  'pr.retargeted': transition,
  'jira.issue.done': outcome,
  'jira.issue.unassigned': outcome,
  'jira.issue.status_changed': transition,
  'jira.issue.sprint_changed': transition,
  'jira.issue.priority_changed': transition,
}

function integrationChange(type: string, parsed: Record<string, any>): string {
  return integrationChanges[type]?.(parsed) ?? ''
}

async function toggle(line: Line) {
  if (openLine.value === line.id) {
    openLine.value = null
    return
  }

  openLine.value = line.id
  deliveries.value = null
  const { data } = await listEventDeliveries({ path: { line: line.id } })
  if (openLine.value === line.id) deliveries.value = data ?? []
}
</script>

<template>
  <h1 class="page-title">{{ t('history.title') }}</h1>

  <section class="panel">
    <Message v-if="error" class="panel-error" severity="error" :closable="false">{{ error }}</Message>

    <ul v-if="lines.length" class="lines">
      <li v-for="line in lines" :key="line.id" class="line">
        <div class="line-row">
          <span class="line-icon"><i :class="['pi', icons[line.type] ?? 'pi-circle']" aria-hidden="true" /></span>
          <div class="line-text">
            <strong>{{ label(line) }}</strong>
            <span v-if="detail(line)">{{ detail(line) }}</span>
          </div>
          <time class="line-date" :datetime="line.created_at">{{ d(new Date(line.created_at), 'short') }}</time>
          <Button
            v-if="isSent(line)"
            :icon="openLine === line.id ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
            severity="secondary"
            text
            size="small"
            :aria-label="t('history.deliveriesToggle')"
            :aria-expanded="openLine === line.id"
            :title="t('history.deliveriesToggle')"
            @click="toggle(line)"
          />
        </div>

        <div v-if="openLine === line.id && deliveries" class="deliveries">
          <p v-if="!deliveries.length" class="empty">{{ t('history.noDelivery') }}</p>
          <article v-for="delivery in deliveries" :key="delivery.id" class="delivery">
            <div class="delivery-head">
              <strong>{{ delivery.hostname ?? t('devices.unknownHost') }}</strong>
              <Tag :value="t(`history.delivery.${delivery.status}`)" :severity="deliverySeverity[delivery.status] ?? 'secondary'" />
            </div>
            <dl>
              <dt>{{ t('history.payload') }}</dt>
              <dd><code>{{ delivery.payload }}</code></dd>
              <template v-if="delivery.exit_code !== null">
                <dt>{{ t('history.exitCode') }}</dt>
                <dd><code>{{ delivery.exit_code }}</code></dd>
              </template>
              <dt>{{ t('history.response') }}</dt>
              <dd>
                <code v-if="delivery.response">{{ delivery.response }}</code>
                <span v-else class="empty">{{ t('history.noResponse') }}</span>
              </dd>
            </dl>
          </article>
        </div>
      </li>
    </ul>
    <p v-else-if="!loading" class="empty">{{ t('history.empty') }}</p>

    <Button
      v-if="hasMore"
      class="load-more"
      :label="t('history.loadMore')"
      severity="secondary"
      outlined
      size="small"
      :loading="loading"
      @click="load(false)"
    />
  </section>
</template>

<style scoped>
.page-title {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.panel {
  max-width: 48rem;
  padding: 1.25rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel-error {
  margin-bottom: 0.75rem;
}

.lines {
  margin: 0;
  padding: 0;
  list-style: none;
}

.line {
  padding: 0.75rem 0;
  border-top: 1px solid var(--p-content-border-color);
}

.line:first-child {
  padding-top: 0;
  border-top: 0;
}

.line:last-child {
  padding-bottom: 0;
}

.line-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem 0.75rem;
}

.line-icon {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: var(--p-content-border-radius);
  background: var(--p-content-hover-background);
  color: var(--p-text-muted-color);
}

.line-text {
  flex: 1 1 12rem;
  display: flex;
  flex-direction: column;
  min-width: 0;
  overflow-wrap: anywhere;
}

.line-text span {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.line-date {
  margin-left: auto;
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
  white-space: nowrap;
}

.deliveries {
  margin: 0.625rem 0 0 2.75rem;
  padding-left: 0.75rem;
  border-left: 2px solid var(--p-content-border-color);
}

.delivery + .delivery {
  margin-top: 0.75rem;
}

.delivery-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.delivery dl {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.125rem 0.75rem;
  margin: 0.25rem 0 0;
  font-size: 0.875rem;
}

.delivery dt {
  color: var(--p-text-muted-color);
}

.delivery dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.delivery code {
  font-size: 0.8125rem;
  white-space: pre-wrap;
}

.empty {
  margin: 0;
  color: var(--p-text-muted-color);
}

.load-more {
  display: flex;
  margin: 1rem auto 0;
}
</style>
