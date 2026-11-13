<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import {
  createWebhook,
  deleteWebhook,
  listWebhooks,
  regenerateWebhookSecret,
  updateWebhook,
  type ListWebhooksResponse,
} from '../client'
import CodeBlock from '../components/CodeBlock.vue'
import WebhookCallsDialog from '../components/WebhookCallsDialog.vue'
import { useWorkspaceStore } from '../stores/workspace'

type Webhook = ListWebhooksResponse[number]

const { t, d } = useI18n()
const toast = useToast()
const workspace = useWorkspaceStore()

const webhooks = ref<ListWebhooksResponse>([])
const listError = ref<string | null>(null)

// The form serves the creation (`editing` null) and the edition
const formVisible = ref(false)
const editing = ref<Webhook | null>(null)
const form = ref({ name: '', events: '', secured: true })
const saving = ref(false)
const formError = ref<string | null>(null)

// Only kept while its dialog is open : the API never gives it back
const secret = ref<{ webhook: Webhook; value: string } | null>(null)
const secretVisible = computed({
  get: () => secret.value !== null,
  set: (open) => { if (!open) secret.value = null },
})

const callsVisible = ref(false)
const callsOf = ref<Webhook | null>(null)

function urlOf(webhook: Webhook): string {
  return `${window.location.origin}/api/hooks/${webhook.uuid}`
}

const curlExample = computed(() =>
  secret.value
    ? `curl -X POST '${urlOf(secret.value.webhook)}' \\\n  -H 'Content-Type: application/json' \\\n  -H 'X-Tower-Secret: ${secret.value.value}' \\\n  -d '{"env": "prod"}'`
    : '',
)

async function load() {
  listError.value = null
  const { data } = await listWebhooks()
  if (data) webhooks.value = data
  else listError.value = t('webhooks.loadError')
}

watch(() => workspace.current?.id, (id) => { if (id) void load() }, { immediate: true })

function eventsOf(webhook: Webhook): string[] {
  return webhook.events.filter((event): event is string => typeof event === 'string')
}

function parseEvents(value: string): string[] {
  return [...new Set(value.split(/[\s,]+/).filter(Boolean))]
}

function openForm(webhook: Webhook | null) {
  editing.value = webhook
  form.value = { name: webhook?.name ?? '', events: webhook ? eventsOf(webhook).join(', ') : '', secured: true }
  formError.value = null
  formVisible.value = true
}

async function save() {
  saving.value = true
  formError.value = null

  const name = form.value.name.trim()
  const events = parseEvents(form.value.events)
  const target = editing.value

  if (target) {
    const { response } = await updateWebhook({ path: { webhook: target.id }, body: { name, events, enabled: target.enabled } })
    saving.value = false
    if (!response?.ok) {
      formError.value = response?.status === 422 ? t('webhooks.invalid') : t('webhooks.saveError')
      return
    }
  } else {
    const { data, response } = await createWebhook({ body: { name, events, secured: form.value.secured } })
    saving.value = false
    if (!data?.webhook) {
      formError.value = response?.status === 422 ? t('webhooks.invalid') : t('webhooks.saveError')
      return
    }
    // An unsecured webhook has no secret to show
    if (data.secret) secret.value = { webhook: data.webhook, value: data.secret }
  }

  formVisible.value = false
  await load()
}

async function setEnabled(webhook: Webhook, enabled: boolean) {
  listError.value = null
  const { data } = await updateWebhook({ path: { webhook: webhook.id }, body: { name: webhook.name, events: eventsOf(webhook), enabled } })
  if (!data) listError.value = t('webhooks.saveError')
  await load()
}

async function regenerate(webhook: Webhook) {
  if (!window.confirm(t('webhooks.regenerateConfirm', { name: webhook.name }))) return

  listError.value = null
  const { data } = await regenerateWebhookSecret({ path: { webhook: webhook.id } })
  if (data?.secret) secret.value = { webhook, value: data.secret }
  else listError.value = t('webhooks.saveError')
}

async function remove(webhook: Webhook) {
  if (!window.confirm(t('webhooks.deleteConfirm', { name: webhook.name }))) return

  listError.value = null
  const { response } = await deleteWebhook({ path: { webhook: webhook.id } })
  if (!response?.ok) listError.value = t('webhooks.deleteError')
  await load()
}

async function copyUrl(webhook: Webhook) {
  try {
    await navigator.clipboard.writeText(urlOf(webhook))
    toast.add({ severity: 'success', summary: t('webhooks.copied'), life: 2000 })
  } catch {
    // Without clipboard access, the address stays selectable on the page
  }
}

function showCalls(webhook: Webhook) {
  callsOf.value = webhook
  callsVisible.value = true
}
</script>

<template>
  <h1 class="page-title">{{ t('webhooks.title') }}</h1>

  <section class="panel">
    <header class="panel-header">
      <p class="description">{{ t('webhooks.description') }}</p>
      <Button :label="t('webhooks.new')" icon="pi pi-plus" size="small" @click="openForm(null)" />
    </header>

    <Message v-if="listError" class="panel-error" severity="error" :closable="false">{{ listError }}</Message>

    <div v-if="webhooks.length" class="table-wrapper">
      <table class="webhooks">
        <thead>
          <tr>
            <th>{{ t('webhooks.enabled') }}</th>
            <th>{{ t('webhooks.nameLabel') }}</th>
            <th>{{ t('webhooks.url') }}</th>
            <th>{{ t('webhooks.eventsLabel') }}</th>
            <th>{{ t('webhooks.lastCall') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="webhook in webhooks" :key="webhook.id" :class="{ 'webhook-disabled': !webhook.enabled }">
            <td>
              <ToggleSwitch
                :model-value="webhook.enabled"
                :aria-label="webhook.enabled ? t('webhooks.disable') : t('webhooks.enable')"
                :title="webhook.enabled ? t('webhooks.enabled') : t('webhooks.disabled')"
                @update:model-value="setEnabled(webhook, $event)"
              />
            </td>
            <td class="webhook-name">
              <strong>{{ webhook.name }}</strong>
              <i
                v-if="!webhook.secured"
                class="pi pi-lock-open webhook-unsecured"
                role="img"
                :aria-label="t('webhooks.unsecured')"
                :title="t('webhooks.unsecuredHint')"
              />
            </td>
            <td>
              <div class="webhook-url">
                <code :title="urlOf(webhook)">{{ urlOf(webhook) }}</code>
                <Button icon="pi pi-copy" severity="secondary" text size="small" :aria-label="t('webhooks.copy')" :title="t('webhooks.copy')" @click="copyUrl(webhook)" />
              </div>
            </td>
            <td>
              <span class="webhook-events">
                <code v-for="event in eventsOf(webhook)" :key="event">{{ event }}</code>
              </span>
            </td>
            <td class="webhook-last-call">
              {{ webhook.last_called_at ? d(new Date(webhook.last_called_at), 'short') : t('webhooks.neverCalled') }}
            </td>
            <td>
              <div class="webhook-buttons">
                <Button icon="pi pi-list" severity="secondary" text size="small" :aria-label="t('webhooks.calls')" :title="t('webhooks.calls')" @click="showCalls(webhook)" />
                <Button icon="pi pi-pencil" severity="secondary" text size="small" :aria-label="t('webhooks.edit')" :title="t('webhooks.edit')" @click="openForm(webhook)" />
                <Button v-if="webhook.secured" icon="pi pi-key" severity="secondary" text size="small" :aria-label="t('webhooks.regenerate')" :title="t('webhooks.regenerate')" @click="regenerate(webhook)" />
                <Button icon="pi pi-trash" severity="secondary" text size="small" :aria-label="t('webhooks.deleteButton')" :title="t('webhooks.deleteButton')" @click="remove(webhook)" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else-if="!listError" class="empty">{{ t('webhooks.empty') }}</p>
  </section>

  <Dialog
    v-model:visible="formVisible"
    modal
    :header="editing ? t('webhooks.edit') : t('webhooks.new')"
    :style="{ width: '32rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <form class="webhook-form" @submit.prevent="save">
      <Message v-if="formError" severity="error" :closable="false">{{ formError }}</Message>
      <div class="field">
        <label for="webhook-name">{{ t('webhooks.nameLabel') }}</label>
        <InputText id="webhook-name" v-model="form.name" maxlength="100" required autofocus fluid />
      </div>
      <div class="field">
        <label for="webhook-events">{{ t('webhooks.eventsLabel') }}</label>
        <InputText id="webhook-events" v-model="form.events" placeholder="deploy.start, notify.phone" required fluid />
        <small>{{ t('webhooks.eventsHint') }}</small>
      </div>
      <!-- Chosen once : a webhook cannot become secured or unsecured afterwards -->
      <template v-if="!editing">
        <label class="secured-option">
          <Checkbox v-model="form.secured" binary />
          {{ t('webhooks.securedLabel') }}
        </label>
        <Message v-if="!form.secured" severity="warn" :closable="false">{{ t('webhooks.unsecuredWarning') }}</Message>
      </template>
      <Button class="form-submit" type="submit" :label="editing ? t('webhooks.save') : t('webhooks.create')" :loading="saving" />
    </form>
  </Dialog>

  <Dialog
    v-model:visible="secretVisible"
    modal
    :closable="false"
    :close-on-escape="false"
    :header="t('webhooks.secretTitle')"
    :style="{ width: '40rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <template v-if="secret">
      <Message severity="warn" :closable="false">{{ t('webhooks.secretWarning') }}</Message>
      <code class="secret">{{ secret.value }}</code>
      <p class="description">{{ t('webhooks.secretUsage') }}</p>
      <CodeBlock :code="curlExample" language="bash" />
    </template>
    <template #footer>
      <Button :label="t('webhooks.secretDone')" @click="secretVisible = false" />
    </template>
  </Dialog>

  <WebhookCallsDialog v-model:visible="callsVisible" :webhook="callsOf" />
</template>

<style scoped>
.page-title {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.panel {
  max-width: 72rem;
  margin-bottom: 1.25rem;
  padding: 1.25rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.panel-header :deep(button) {
  flex: none;
}

.panel-error {
  margin-bottom: 0.75rem;
}

.description,
.empty {
  margin: 0;
  color: var(--p-text-muted-color);
}

.table-wrapper {
  overflow-x: auto;
}

.webhooks {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.webhooks th,
.webhooks td {
  padding: 0.375rem 0.5rem;
  border-bottom: 1px solid var(--p-content-border-color);
  text-align: left;
  vertical-align: middle;
}

.webhooks th {
  color: var(--p-text-muted-color);
  font-weight: 500;
  white-space: nowrap;
}

.webhooks tbody tr:last-child td {
  border-bottom: 0;
}

.webhook-disabled td:not(:first-child):not(:last-child) {
  opacity: 0.6;
}

.webhook-name {
  overflow-wrap: anywhere;
}

.webhook-unsecured {
  margin-left: 0.375rem;
  color: light-dark(var(--p-orange-600), var(--p-orange-400));
  font-size: 0.875rem;
}

.secured-option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.webhook-buttons {
  display: flex;
  justify-content: flex-end;
  gap: 0.125rem;
}

.webhook-url {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.webhook-url code {
  max-width: 16rem;
  padding: 0.125rem 0.375rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  background: var(--p-content-hover-background);
  border-radius: var(--p-content-border-radius);
  font-size: 0.8125rem;
}

.webhook-events {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}

.webhook-events code {
  padding: 0.0625rem 0.3125rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  font-size: 0.8125rem;
}

.webhook-last-call {
  color: var(--p-text-muted-color);
  white-space: nowrap;
}

.webhook-form,
.field {
  display: flex;
  flex-direction: column;
}

.webhook-form {
  gap: 1rem;
}

.field {
  gap: 0.375rem;
}

.field small {
  color: var(--p-text-muted-color);
}

.form-submit {
  align-self: flex-end;
}

.secret {
  display: block;
  margin: 1rem 0;
  padding: 0.5rem 0.75rem;
  overflow-x: auto;
  white-space: nowrap;
  background: var(--p-content-hover-background);
  border-radius: var(--p-content-border-radius);
  font-size: 0.875rem;
}

.secret + .description {
  margin-bottom: 0.75rem;
}

@media (max-width: 30rem) {
  .panel-header {
    flex-direction: column;
  }
}
</style>
