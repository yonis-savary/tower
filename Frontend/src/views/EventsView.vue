<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import {
  createQuickAction,
  deleteQuickAction,
  listQuickActions,
  triggerEvent,
  triggerQuickAction,
  type ListQuickActionsResponse,
} from '../client'
import EventFields from '../components/EventFields.vue'
import EmptyState from '../components/EmptyState.vue'
import { useWorkspaceStore } from '../stores/workspace'

type QuickAction = ListQuickActionsResponse[number]

const { t } = useI18n()
const toast = useToast()
const workspace = useWorkspaceStore()

const actions = ref<ListQuickActionsResponse>([])
const actionsError = ref<string | null>(null)

const type = ref('')
const payload = ref('')
const sending = ref(false)
const sendError = ref<string | null>(null)

const dialogVisible = ref(false)
const newAction = ref({ name: '', description: '', type: '', payload: '' })
const saving = ref(false)
const saveError = ref<string | null>(null)

async function loadActions() {
  const { data } = await listQuickActions()
  actions.value = data ?? []
}

watch(() => workspace.current?.id, (id) => { if (id) void loadActions() }, { immediate: true })

function announceSent(eventType: string) {
  toast.add({ severity: 'success', summary: t('events.sent', { type: eventType }), life: 3000 })
}

async function send() {
  sending.value = true
  sendError.value = null

  const { response } = await triggerEvent({ body: { type: type.value.trim(), payload: payload.value.trim() || '{}' } })
  sending.value = false

  if (!response?.ok) {
    sendError.value = t('events.invalid')
    return
  }

  announceSent(type.value.trim())
}

async function launch(action: QuickAction) {
  actionsError.value = null

  const { response } = await triggerQuickAction({ path: { action: action.id } })
  if (response?.ok) announceSent(action.type)
  else actionsError.value = t('events.launchError')
}

async function remove(action: QuickAction) {
  if (!window.confirm(t('events.deleteConfirm', { name: action.name }))) return

  actionsError.value = null
  const { response } = await deleteQuickAction({ path: { action: action.id } })
  if (!response?.ok) actionsError.value = t('events.deleteError')
  await loadActions()
}

function openDialog() {
  newAction.value = { name: '', description: '', type: '', payload: '' }
  saveError.value = null
  dialogVisible.value = true
}

async function save() {
  saving.value = true
  saveError.value = null

  const { name, description, type: eventType, payload: eventPayload } = newAction.value
  const { response } = await createQuickAction({
    body: {
      name: name.trim(),
      description: description.trim() || null,
      type: eventType.trim(),
      payload: eventPayload.trim() || '{}',
    },
  })
  saving.value = false

  if (!response?.ok) {
    saveError.value = t('events.invalid')
    return
  }

  dialogVisible.value = false
  await loadActions()
}
</script>

<template>
  <h1 class="page-title">{{ t('events.title') }}</h1>

  <div class="panels">
    <section class="panel">
      <header class="panel-header">
        <h2>{{ t('events.manualTitle') }}</h2>
      </header>
      <p class="description">{{ t('events.manualDescription') }}</p>

      <form class="event-form manual-form" @submit.prevent="send">
        <Message v-if="sendError" severity="error" :closable="false">{{ sendError }}</Message>
        <EventFields v-model:type="type" v-model:payload="payload" />
        <Button class="event-submit" type="submit" :label="t('events.launchButton')" icon="pi pi-bolt" :loading="sending" />
      </form>
    </section>

    <section class="panel">
      <header class="panel-header">
        <h2>{{ t('events.quickActionsTitle') }}</h2>
        <Button :label="t('events.newQuickAction')" icon="pi pi-plus" size="small" @click="openDialog" />
      </header>

      <Message v-if="actionsError" class="panel-error" severity="error" :closable="false">{{ actionsError }}</Message>

      <ul v-if="actions.length" class="quick-actions">
        <li v-for="action in actions" :key="action.id" class="quick-action">
          <div class="quick-action-identity">
            <strong>{{ action.name }}</strong>
            <span v-if="action.description" class="quick-action-description">{{ action.description }}</span>
            <code>{{ action.type }}</code>
          </div>
          <div class="quick-action-buttons">
            <Button
              icon="pi pi-trash"
              severity="secondary"
              text
              size="small"
              :aria-label="t('events.deleteButton')"
              :title="t('events.deleteButton')"
              @click="remove(action)"
            />
            <Button :label="t('events.launchButton')" icon="pi pi-bolt" size="small" @click="launch(action)" />
          </div>
        </li>
      </ul>
      <EmptyState v-else icon="pi pi-bolt" :title="t('events.noQuickActionTitle')" :hint="t('events.noQuickActionHint')" compact />
    </section>
  </div>

  <Dialog
    v-model:visible="dialogVisible"
    modal
    :header="t('events.newQuickAction')"
    :style="{ width: '32rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <form class="event-form" @submit.prevent="save">
      <Message v-if="saveError" severity="error" :closable="false">{{ saveError }}</Message>
      <div class="dialog-field">
        <label for="quick-action-name">{{ t('events.nameLabel') }}</label>
        <InputText id="quick-action-name" v-model="newAction.name" maxlength="100" required autofocus fluid />
      </div>
      <div class="dialog-field">
        <label for="quick-action-description">{{ t('events.descriptionLabel') }}</label>
        <Textarea id="quick-action-description" v-model="newAction.description" maxlength="255" rows="2" auto-resize fluid />
      </div>
      <EventFields v-model:type="newAction.type" v-model:payload="newAction.payload" />
      <Button class="event-submit" type="submit" :label="t('events.saveButton')" :loading="saving" />
    </form>
  </Dialog>
</template>

<style scoped>
.panels {
  display: flex;
  flex-wrap: wrap;
  gap: 1.25rem;
  max-width: 72rem;
}

.panel {
  flex: 1 1 22rem;
  display: flex;
  flex-direction: column;
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
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.panel-header h2 {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
}

.panel-error {
  margin-bottom: 0.75rem;
}

.description {
  margin: 0;
  color: var(--p-text-muted-color);
}

.description {
  margin-bottom: 1rem;
}

.quick-actions {
  margin: 0;
  padding: 0;
  list-style: none;
}

.quick-action {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--p-content-border-color);
}

.quick-action:first-child {
  padding-top: 0;
  border-top: 0;
}

.quick-action:last-child {
  padding-bottom: 0;
}

.quick-action-identity {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  min-width: 0;
  overflow-wrap: anywhere;
}

.quick-action-description {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.quick-action-identity code {
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
}

.quick-action-buttons {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  margin-left: auto;
}

.event-form,
.dialog-field {
  display: flex;
  flex-direction: column;
}

.event-form {
  gap: 1rem;
}

.dialog-field {
  gap: 0.375rem;
}

.event-submit {
  align-self: flex-end;
}
</style>
