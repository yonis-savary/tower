<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import {
  listRecentEvents,
  listRefusedWebhookCalls,
  type ListRecentEventsResponse,
  type ListRefusedWebhookCallsResponse,
} from '../client'
import AppIcon from './AppIcon.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { HISTORY_ICONS, historyDetail, historyLabel, parseDetails } from '../utils/historyLine'

type Item =
  | { kind: 'event'; key: string; date: string; event: ListRecentEventsResponse[number]; label: string; detail: string }
  | { kind: 'refused'; key: string; date: string; call: ListRefusedWebhookCallsResponse[number] }

const MAX_ITEMS = 15

const { t, te, d } = useI18n()
const router = useRouter()
const workspace = useWorkspaceStore()

const events = ref<ListRecentEventsResponse>([])
const refusedCalls = ref<ListRefusedWebhookCallsResponse>([])
// null until the first answer
const filled = defineModel<boolean | null>('filled', { default: null })

async function load() {
  const [eventList, callList] = await Promise.all([
    workspace.hasSection('events') ? listRecentEvents() : null,
    workspace.hasSection('webhooks') ? listRefusedWebhookCalls() : null,
  ])
  events.value = eventList?.data ?? []
  refusedCalls.value = callList?.data ?? []
  filled.value = events.value.length + refusedCalls.value.length > 0
}

watch(() => [workspace.current?.id, workspace.current?.sections], ([id]) => { if (id) void load() }, { immediate: true })

// Newest first, events and refused calls mixed
const items = computed<Item[]>(() => [
  ...events.value.map((event) => {
    const parsed = parseDetails(event.details)
    return {
      kind: 'event' as const,
      key: `event-${event.id}`,
      date: event.created_at,
      event,
      label: historyLabel({ t, te }, event.type),
      detail: historyDetail({ t, te }, event.type, parsed),
    }
  }),
  ...refusedCalls.value.map((call) => ({ kind: 'refused' as const, key: `call-${call.id}`, date: call.created_at, call })),
].sort((a, b) => b.date.localeCompare(a.date)).slice(0, MAX_ITEMS))

function openItem(item: Item) {
  void router.push(item.kind === 'event' ? { name: 'history', query: { line: item.event.id } } : { name: 'webhooks' })
}

defineExpose({ load })
</script>

<template>
  <!-- Hidden while nothing happened -->
  <section v-if="items.length" class="panel">
    <header class="panel-header">
      <h2>{{ t('home.activityTitle') }}</h2>
      <Button :label="t('nav.history')" severity="secondary" text size="small" @click="router.push({ name: 'history' })" />
    </header>

    <ul class="items">
      <li v-for="item in items" :key="item.key">
        <button type="button" class="item" :class="{ 'item-refused': item.kind === 'refused' }" @click="openItem(item)">
          <template v-if="item.kind === 'event'">
            <AppIcon class="item-icon" :icon="HISTORY_ICONS[item.event.type] ?? 'pi pi-bolt'" />
            <span class="item-text">
              <strong>{{ item.label }}</strong>
              <span class="item-detail" :title="item.detail">{{ item.detail }}</span>
            </span>
            <span class="deliveries" :title="t('home.deliveriesTitle')">
              <span v-if="item.event.deliveries.succeeded" class="delivery delivery-succeeded"><i class="pi pi-check" aria-hidden="true" />{{ item.event.deliveries.succeeded }}</span>
              <span v-if="item.event.deliveries.failed" class="delivery delivery-failed"><i class="pi pi-times" aria-hidden="true" />{{ item.event.deliveries.failed }}</span>
              <span v-if="item.event.deliveries.waiting" class="delivery delivery-waiting"><i class="pi pi-clock" aria-hidden="true" />{{ item.event.deliveries.waiting }}</span>
            </span>
          </template>

          <template v-else>
            <i class="item-icon pi pi-exclamation-triangle" aria-hidden="true" />
            <span class="item-text">
              <strong>{{ t('home.refusedCall', { name: item.call.webhook_name }) }}</strong>
              <span class="item-detail">{{ t(`webhooks.callStatus.${item.call.status}`) }}</span>
            </span>
          </template>

          <time class="item-date" :datetime="item.date">{{ d(new Date(item.date), 'short') }}</time>
        </button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.panel {
  min-width: 0;
  padding: var(--panel-padding);
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel-header {
  display: flex;
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

.items {
  /* About eight lines, the rest scrolls */
  max-height: 26rem;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
  overscroll-behavior: contain;
}

.items li + li {
  border-top: 1px solid var(--p-content-border-color);
}

.item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.5rem 0.25rem;
  color: inherit;
  background: none;
  border: 0;
  border-radius: var(--p-content-border-radius);
  font: inherit;
  font-size: 0.875rem;
  text-align: left;
  cursor: pointer;
}

.item:hover {
  background: var(--p-content-hover-background);
}

.item:focus-visible {
  outline: 2px solid var(--p-primary-color);
}

.item-icon {
  flex: none;
  width: 1.25rem;
  color: var(--p-text-muted-color);
  text-align: center;
}

.item-refused .item-icon {
  color: var(--p-orange-500);
}

.item-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.item-detail {
  overflow: hidden;
  color: var(--p-text-muted-color);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.deliveries {
  display: flex;
  flex: none;
  gap: 0.5rem;
  font-size: 0.8125rem;
}

.delivery {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

.delivery .pi {
  font-size: 0.75rem;
}

.delivery-succeeded {
  color: var(--p-green-600);
}

.delivery-failed {
  color: var(--p-red-500);
}

.delivery-waiting {
  color: var(--p-text-muted-color);
}

.item-date {
  flex: none;
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
  white-space: nowrap;
}
</style>
