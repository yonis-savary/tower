<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import EmptyState from './EmptyState.vue'
import Tag from 'primevue/tag'
import { listWebhookCalls, type ListWebhookCallsResponse, type ListWebhooksResponse } from '../client'

const PAGE_SIZE = 50
const statusSeverity: Record<string, string> = { accepted: 'success', unauthorized: 'danger', disabled: 'secondary', invalid: 'warn' }

const visible = defineModel<boolean>('visible', { required: true })
const props = defineProps<{ webhook: ListWebhooksResponse[number] | null }>()

const { t, te, d } = useI18n()

const calls = ref<ListWebhookCallsResponse>([])
const hasMore = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const openCall = ref<number | null>(null)

async function load(reset: boolean) {
  const webhook = props.webhook
  if (!webhook) return

  loading.value = true
  error.value = null

  const before = reset ? undefined : calls.value.at(-1)?.id
  const { data } = await listWebhookCalls({ path: { webhook: webhook.id }, query: { before } })
  loading.value = false

  if (!data) {
    error.value = t('webhooks.callsLoadError')
    return
  }

  calls.value = reset ? data : [...calls.value, ...data]
  hasMore.value = data.length === PAGE_SIZE
}

watch(visible, (open) => {
  calls.value = []
  openCall.value = null
  if (open) void load(true)
})

function statusLabel(status: string): string {
  const key = `webhooks.callStatus.${status}`
  return te(key) ? t(key) : status
}

function prettyPayload(payload: string): string {
  try {
    return JSON.stringify(JSON.parse(payload), null, 2)
  } catch {
    return payload
  }
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="t('webhooks.callsTitle', { name: webhook?.name ?? '' })"
    :style="{ width: '44rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <Message v-if="error" class="dialog-error" severity="error" :closable="false">{{ error }}</Message>

    <ul v-if="calls.length" class="calls">
      <li v-for="call in calls" :key="call.id" class="call">
        <button type="button" class="call-row" :aria-expanded="openCall === call.id" @click="openCall = openCall === call.id ? null : call.id">
          <Tag :value="statusLabel(call.status)" :severity="statusSeverity[call.status] ?? 'secondary'" />
          <span class="call-origin">
            <code>{{ call.ip ?? t('webhooks.unknownIp') }}</code>
            <span v-if="call.user_agent" class="call-agent">{{ call.user_agent }}</span>
          </span>
          <time class="call-date" :datetime="call.created_at">{{ d(new Date(call.created_at), 'short') }}</time>
          <i :class="openCall === call.id ? 'pi pi-chevron-up' : 'pi pi-chevron-down'" aria-hidden="true" />
        </button>
        <div v-if="openCall === call.id" class="call-detail">
          <strong>{{ t('webhooks.payload') }}</strong>
          <pre v-if="call.payload">{{ prettyPayload(call.payload) }}</pre>
          <p v-else class="empty">{{ t('webhooks.noPayload') }}</p>
        </div>
      </li>
    </ul>
    <EmptyState v-else-if="!loading" icon="pi pi-list" :title="t('webhooks.noCallTitle')" :hint="t('webhooks.noCallHint')" compact />

    <Button
      v-if="hasMore"
      class="load-more"
      :label="t('webhooks.loadMore')"
      severity="secondary"
      outlined
      size="small"
      :loading="loading"
      @click="load(false)"
    />
  </Dialog>
</template>

<style scoped>
.dialog-error {
  margin-bottom: 1rem;
}

.calls {
  margin: 0;
  padding: 0;
  list-style: none;
}

.call + .call {
  border-top: 1px solid var(--p-content-border-color);
}

.call-row {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.625rem 0.25rem;
  border: 0;
  background: transparent;
  color: var(--p-text-color);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.call-row:hover {
  background: var(--p-content-hover-background);
}

.call-origin {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.call-agent {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
}

.call-date {
  flex: none;
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.call-detail {
  padding: 0 0.25rem 0.75rem;
}

.call-detail pre {
  margin: 0.375rem 0 0;
  padding: 0.75rem;
  max-height: 20rem;
  overflow: auto;
  background: var(--p-content-hover-background);
  border-radius: var(--p-content-border-radius);
  font-size: 0.8125rem;
}

.empty {
  margin: 0.375rem 0 0;
  color: var(--p-text-muted-color);
}

.load-more {
  margin-top: 1rem;
}
</style>
