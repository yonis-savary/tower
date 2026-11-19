<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import {
  listEventDeliveries,
  listHistory,
  type ListEventDeliveriesResponse,
  type ListHistoryResponse,
} from '../client'
import AppIcon from '../components/AppIcon.vue'
import EmptyState from '../components/EmptyState.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { DELIVERY_SEVERITY, HISTORY_ICONS, historyDetail, historyLabel, isSent, parseDetails } from '../utils/historyLine'

type Line = ListHistoryResponse[number] & { parsed: Record<string, any> }

const PAGE_SIZE = 50

const { t, te, d } = useI18n()
const i18n = { t, te }
const workspace = useWorkspaceStore()
const route = useRoute()
const router = useRouter()

const lines = ref<Line[]>([])
const hasMore = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const openLine = ref<number | null>(null)
const deliveries = ref<ListEventDeliveriesResponse | null>(null)

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

  const page = data.map((line) => ({ ...line, parsed: parseDetails(line.details) }))
  lines.value = reset ? page : [...lines.value, ...page]
  hasMore.value = data.length === PAGE_SIZE
}

watch(
  () => workspace.current?.id,
  async (id) => {
    openLine.value = null
    if (!id || (await switchToRequestedWorkspace(id))) return

    await load(true)
    openRequestedLine()
  },
  { immediate: true },
)

// Opened again while already on this screen, by a click on a notification
watch(
  () => route.query.line,
  async (line) => {
    const id = workspace.current?.id
    if (!line || !id || (await switchToRequestedWorkspace(id))) return

    await load(true)
    openRequestedLine()
  },
)

// A notification links to the line in the workspace of its event, through ?workspace=<id>
async function switchToRequestedWorkspace(current: number): Promise<boolean> {
  const requested = Number(route.query.workspace)
  if (!requested || requested === current) return false

  await workspace.select(requested)
  if (workspace.current?.id === requested) return true

  // No longer a member of it : the line stays out of reach
  void router.replace({ query: {} })
  return false
}

// Opened from another screen through ?line=<id>, when the line is on the first page
function openRequestedLine() {
  if (!route.query.line) return

  const line = lines.value.find(({ id }) => id === Number(route.query.line))
  void router.replace({ query: {} })
  if (line && isSent(line.type)) void toggle(line)
}

function label(line: Line): string {
  return historyLabel(i18n, line.type)
}

function detail(line: Line): string {
  return historyDetail(i18n, line.type, line.parsed)
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

  <EmptyState v-if="!lines.length && !loading && !error" icon="pi pi-history" :title="t('history.emptyTitle')" :hint="t('history.emptyHint')" />

  <section v-else class="panel">
    <Message v-if="error" class="panel-error" severity="error" :closable="false">{{ error }}</Message>

    <div v-if="lines.length" class="table-wrapper">
      <table class="lines">
        <thead>
          <tr>
            <th colspan="2">{{ t('history.eventColumn') }}</th>
            <th>{{ t('history.detailColumn') }}</th>
            <th>{{ t('history.dateColumn') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <template v-for="line in lines" :key="line.id">
            <tr class="line" :class="{ 'line-open': openLine === line.id }">
              <td class="line-icon"><AppIcon :icon="HISTORY_ICONS[line.type] ?? 'pi pi-circle'" /></td>
              <td class="line-label">{{ label(line) }}</td>
              <td class="line-detail" :title="detail(line)">{{ detail(line) }}</td>
              <td class="line-date"><time :datetime="line.created_at">{{ d(new Date(line.created_at), 'short') }}</time></td>
              <td class="line-toggle">
                <Button
                  v-if="isSent(line.type)"
                  :icon="openLine === line.id ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                  severity="secondary"
                  text
                  size="small"
                  :aria-label="t('history.deliveriesToggle')"
                  :aria-expanded="openLine === line.id"
                  :title="t('history.deliveriesToggle')"
                  @click="toggle(line)"
                />
              </td>
            </tr>

            <tr v-if="openLine === line.id && deliveries" class="deliveries-row">
              <td colspan="5">
                <div class="deliveries">
                  <p v-if="!deliveries.length" class="empty">{{ t('history.noDelivery') }}</p>
                  <article v-for="delivery in deliveries" :key="delivery.id" class="delivery">
                    <div class="delivery-head">
                      <strong>{{ delivery.hostname ?? t('devices.unknownHost') }}</strong>
                      <Tag :value="t(`history.delivery.${delivery.status}`)" :severity="DELIVERY_SEVERITY[delivery.status] ?? 'secondary'" />
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
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

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
.panel {
  max-width: 72rem;
  padding: var(--panel-padding);
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel-error {
  margin-bottom: 0.75rem;
}

.table-wrapper {
  overflow-x: auto;
}

.lines {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.lines th,
.lines td {
  padding: 0.25rem 0.5rem;
  border-bottom: 1px solid var(--p-content-border-color);
  text-align: left;
  vertical-align: middle;
}

.lines th {
  color: var(--p-text-muted-color);
  font-weight: 500;
  white-space: nowrap;
}

.lines tbody tr:last-child td {
  border-bottom: 0;
}

.line-open td {
  border-bottom: 0;
}

.lines .line-icon {
  width: 1.5rem;
  padding-right: 0;
  color: var(--p-text-muted-color);
  text-align: center;
}

.line-label {
  font-weight: 600;
  white-space: nowrap;
}

.line-detail {
  max-width: 0;
  width: 100%;
  overflow: hidden;
  color: var(--p-text-muted-color);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.line-date {
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
  white-space: nowrap;
}

.lines .line-toggle {
  width: 2.5rem;
  padding: 0;
}

.deliveries {
  margin: 0 0 0.5rem 2rem;
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

/* A phone : each line is a small block, the detail and the date under the label, instead of columns out of sight */
@media (max-width: 48rem) {
  .panel {
    padding-block: 0.25rem;
  }

  .lines thead {
    display: none;
  }

  .lines,
  .lines tbody {
    display: block;
  }

  .line {
    display: grid;
    grid-template-columns: 1.5rem minmax(0, 1fr) auto;
    grid-template-areas:
      'icon label toggle'
      'icon detail toggle'
      'icon date toggle';
    align-items: center;
    column-gap: 0.5rem;
    padding: 0.625rem 0;
    border-bottom: 1px solid var(--p-content-border-color);
  }

  .lines tbody .line:last-of-type,
  .line-open {
    border-bottom: 0;
  }

  .lines .line td {
    display: block;
    width: auto;
    max-width: none;
    padding: 0;
    border: 0;
  }

  .lines .line-icon {
    grid-area: icon;
    align-self: start;
    padding-top: 0.125rem;
  }

  .line-label {
    grid-area: label;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .line-detail {
    grid-area: detail;
  }

  .line-detail:empty {
    display: none !important;
  }

  .line-date {
    grid-area: date;
    font-size: 0.75rem;
  }

  .lines .line-toggle {
    grid-area: toggle;
  }

  .deliveries-row {
    display: block;
    border-bottom: 1px solid var(--p-content-border-color);
  }

  .deliveries-row td {
    display: block;
    padding: 0 0 0.5rem;
  }

  .deliveries {
    margin-left: 0.5rem;
  }
}
</style>
