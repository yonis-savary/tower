<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import {
  downloadConnector,
  downloadHandler,
  listAccessKeys,
  revokeAccessKey,
  rotateAccessKeySecret,
  updateAccessKeyListen,
  type CreateAccessKeyResponse,
  type ListAccessKeysResponse,
} from '../client'
import EventTypeInput from './EventTypeInput.vue'
import { saveFile } from '../utils/saveFile'
import ConfigCreateDialog from './ConfigCreateDialog.vue'
import ConfigSecretDialog from './ConfigSecretDialog.vue'
import HandlersDialog from './HandlersDialog.vue'
import EmptyState from './EmptyState.vue'
import HandlerDialog from './HandlerDialog.vue'

type AccessKey = ListAccessKeysResponse[number]

const { t, d } = useI18n()

const keys = ref<ListAccessKeysResponse>([])
// The key whose listened events are being edited
const editing = ref<number | null>(null)
const editedListen = ref('')
const savingListen = ref(false)
const downloading = ref<'connector' | 'handler' | null>(null)
const createVisible = ref(false)
const duplicated = ref<AccessKey | null>(null)
const handlerVisible = ref(false)
const error = ref<string | null>(null)
// The key whose handler rules are edited
const handlersKey = ref<AccessKey | null>(null)
const handlersVisible = ref(false)
const rotating = ref<number | null>(null)
// The configuration with its new secret, shown once
const rotated = ref<CreateAccessKeyResponse | null>(null)

async function loadKeys() {
  const { data } = await listAccessKeys()
  keys.value = data ?? []
}

onMounted(loadKeys)

async function downloadClient() {
  downloading.value = 'connector'
  error.value = null
  const { data } = await downloadConnector({ parseAs: 'blob' })
  downloading.value = null

  if (data instanceof Blob) saveFile(data, 'tower-connector.pyz')
  else error.value = t('download.connectorError')
}

async function downloadBasicHandler() {
  downloading.value = 'handler'
  error.value = null
  const { data } = await downloadHandler({ parseAs: 'blob' })
  downloading.value = null

  if (!(data instanceof Blob)) {
    error.value = t('download.handlerError')
    return
  }

  saveFile(data, 'tower-handler.pyz')
  handlerVisible.value = true
}

function parseListen(value: string): string[] {
  return value.split(/[\s,]+/).filter(Boolean)
}

function listenLabel(key: AccessKey): string {
  const patterns = (key.listen ?? []) as string[]
  return patterns.length ? patterns.join(', ') : t('download.listenNothing')
}

function editListen(key: AccessKey) {
  editing.value = key.id!
  editedListen.value = ((key.listen ?? []) as string[]).join(', ')
}

async function saveListen(id: number) {
  savingListen.value = true
  error.value = null

  const { response } = await updateAccessKeyListen({ path: { key: id }, body: { listen: parseListen(editedListen.value) } })
  savingListen.value = false

  if (!response?.ok) {
    error.value = t('download.listenError')
    return
  }

  editing.value = null
  await loadKeys()
}

function handlersLabel(key: AccessKey): string {
  const count = (key.handlers ?? []).length
  return count ? t('handlers.count', { count }, count) : t('handlers.none')
}

function openCreate(source: AccessKey | null) {
  duplicated.value = source
  createVisible.value = true
}

function editHandlers(key: AccessKey) {
  handlersKey.value = key
  handlersVisible.value = true
}

async function onRotated(created: CreateAccessKeyResponse) {
  rotated.value = created
  await loadKeys()
}

async function rotate(key: AccessKey) {
  if (!window.confirm(t('handlers.rotateConfirm', { name: key.name }))) return

  rotating.value = key.id
  error.value = null
  const { data } = await rotateAccessKeySecret({ path: { key: key.id } })
  rotating.value = null

  if (!data) {
    error.value = t('handlers.rotateError')
    return
  }

  await onRotated(data)
}

async function revoke(id: number, keyName: string) {
  if (!window.confirm(t('download.revokeConfirm', { name: keyName }))) return

  const { response } = await revokeAccessKey({ path: { key: id } })
  if (!response?.ok) error.value = t('download.revokeError')
  await loadKeys()
}
</script>

<template>
  <Message v-if="error" class="panel-error" severity="error" :closable="false">{{ error }}</Message>

  <div class="connect-actions">
    <Button :label="t('nav.downloadClient')" icon="pi pi-download" severity="secondary" outlined :loading="downloading === 'connector'" @click="downloadClient" />
    <Button :label="t('nav.downloadHandler')" icon="pi pi-comments" severity="secondary" outlined :loading="downloading === 'handler'" @click="downloadBasicHandler" />
    <Button :label="t('download.createTab')" icon="pi pi-plus" @click="openCreate(null)" />
  </div>

  <h3 class="configs-title">{{ t('download.manageTab') }}</h3>
  <ul v-if="keys.length" class="keys">
    <li v-for="key in keys" :key="key.id">
      <div class="key-identity">
        <strong>{{ key.name }}</strong>
        <code>{{ key.client_id }}</code>
        <small>
          {{ key.last_used_at ? t('download.lastUsed', { date: d(new Date(key.last_used_at), 'short') }) : t('download.neverUsed') }}
        </small>
        <form v-if="editing === key.id" class="listen-form" @submit.prevent="saveListen(key.id!)">
          <EventTypeInput v-model="editedListen" :aria-label="t('download.listenLabel')" size="small" list suggest-patterns autofocus />
          <Button type="submit" :label="t('download.listenSave')" size="small" :loading="savingListen" />
          <Button :label="t('download.listenCancel')" severity="secondary" text size="small" @click="editing = null" />
        </form>
        <small v-else class="key-listen">
          <i class="pi pi-bell" aria-hidden="true" />
          {{ t('download.listening', { events: listenLabel(key) }) }}
          <Button :label="t('download.listenEdit')" link size="small" class="listen-edit" @click="editListen(key)" />
        </small>
        <small class="key-listen">
          <i class="pi pi-cog" aria-hidden="true" />
          {{ handlersLabel(key) }}
          <Button :label="t('handlers.edit')" link size="small" class="listen-edit" @click="editHandlers(key)" />
        </small>
      </div>
      <div class="key-actions">
        <Button :label="t('download.duplicate')" icon="pi pi-copy" severity="secondary" text size="small" @click="openCreate(key)" />
        <Button :label="t('handlers.rotate')" icon="pi pi-refresh" severity="secondary" text size="small" :loading="rotating === key.id" @click="rotate(key)" />
        <Button :label="t('download.revokeButton')" icon="pi pi-ban" severity="danger" text size="small" @click="revoke(key.id!, key.name!)" />
      </div>
    </li>
  </ul>
  <EmptyState v-else icon="pi pi-key" :title="t('download.emptyTitle')" :hint="t('download.emptyHint')" compact />

  <ConfigCreateDialog v-model:visible="createVisible" :source="duplicated" @created="loadKeys" />
  <HandlerDialog v-model:visible="handlerVisible" />
  <HandlersDialog v-model:visible="handlersVisible" :access-key="handlersKey" @saved="onRotated" />
  <ConfigSecretDialog v-model="rotated" />
</template>

<style scoped>
.panel-error {
  margin-bottom: 1rem;
}

.connect-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.configs-title {
  margin: 1.25rem 0 0.25rem;
  font-size: 0.9375rem;
  font-weight: 600;
}

.keys {
  max-height: 20rem;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.keys li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--p-content-border-color);
}

.keys li:first-child {
  border-top: 0;
}

.key-identity {
  flex-basis: 14rem;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  min-width: 0;
}

.key-actions {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.key-identity code {
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.8125rem;
}

.key-identity small {
  color: var(--p-text-muted-color);
}

.key-listen {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.375rem;
  overflow-wrap: anywhere;
}

.key-listen .listen-edit {
  padding: 0;
  font-size: inherit;
}

.listen-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
  margin-top: 0.25rem;
}

.listen-form :deep(input) {
  flex: 1 1 10rem;
}
</style>
