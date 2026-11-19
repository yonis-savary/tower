<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import {
  downloadConnector,
  downloadHandler,
  listAccessKeys,
  revokeAccessKey,
  type CreateAccessKeyResponse,
  type ListAccessKeysResponse,
} from '../client'
import { saveFile } from '../utils/saveFile'
import ConfigCreateDialog from './ConfigCreateDialog.vue'
import ConfigDetail from './ConfigDetail.vue'
import ConfigSecretDialog from './ConfigSecretDialog.vue'
import EmptyState from './EmptyState.vue'
import HandlerDialog from './HandlerDialog.vue'

type AccessKey = ListAccessKeysResponse[number]

const { t, d } = useI18n()

const keys = ref<ListAccessKeysResponse>([])
const selectedId = ref<number | null>(null)
const downloading = ref<'connector' | 'handler' | null>(null)
const createVisible = ref(false)
const duplicated = ref<AccessKey | null>(null)
const handlerVisible = ref(false)
const error = ref<string | null>(null)
// The configuration with its new secret, shown once
const rotated = ref<CreateAccessKeyResponse | null>(null)

const selected = computed(() => keys.value.find(({ id }) => id === selectedId.value) ?? keys.value[0] ?? null)

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

function openCreate(source: AccessKey | null) {
  duplicated.value = source
  createVisible.value = true
}

async function onRotated(created: CreateAccessKeyResponse) {
  rotated.value = created
  await loadKeys()
}

async function revoke(key: AccessKey) {
  if (!window.confirm(t('download.revokeConfirm', { name: key.name }))) return

  error.value = null
  const { response } = await revokeAccessKey({ path: { key: key.id } })
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

  <div v-if="keys.length" class="configs">
    <aside class="config-list">
      <h3 class="configs-title">{{ t('download.manageTab') }}</h3>
      <ul>
        <li v-for="key in keys" :key="key.id" :class="{ selected: key.id === selected?.id }">
          <button type="button" class="config-item" :aria-current="key.id === selected?.id" @click="selectedId = key.id">
            <strong>{{ key.name }}</strong>
            <small>{{ key.last_used_at ? t('download.lastUsed', { date: d(new Date(key.last_used_at), 'short') }) : t('download.neverUsed') }}</small>
          </button>
          <div class="config-actions">
            <Button icon="pi pi-copy" severity="secondary" text rounded size="small" :aria-label="t('download.duplicate')" :title="t('download.duplicate')" @click="openCreate(key)" />
            <Button icon="pi pi-ban" severity="danger" text rounded size="small" :aria-label="t('download.revokeButton')" :title="t('download.revokeButton')" @click="revoke(key)" />
          </div>
        </li>
      </ul>
    </aside>

    <ConfigDetail v-if="selected" :access-key="selected" class="config-pane" @changed="loadKeys" @rotated="onRotated" />
  </div>
  <EmptyState v-else icon="pi pi-key" :title="t('download.emptyTitle')" :hint="t('download.emptyHint')" compact />

  <ConfigCreateDialog v-model:visible="createVisible" :source="duplicated" @created="loadKeys" />
  <HandlerDialog v-model:visible="handlerVisible" />
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

.configs {
  display: grid;
  grid-template-columns: minmax(13rem, 17rem) minmax(0, 1fr);
  gap: 1.5rem;
  margin-top: 1.25rem;
}

@media (max-width: 48rem) {
  .configs {
    grid-template-columns: minmax(0, 1fr);
  }
}

.configs-title {
  margin: 0 0 0.5rem;
  font-size: 0.9375rem;
  font-weight: 600;
}

.config-list ul {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.config-list li {
  display: flex;
  align-items: center;
  border-radius: var(--p-content-border-radius);
}

.config-list li:hover {
  background: var(--p-content-hover-background);
}

.config-list li.selected {
  background: var(--p-highlight-background);
  color: var(--p-highlight-color);
}

.config-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  min-width: 0;
  padding: 0.5rem 0.625rem;
  color: inherit;
  background: transparent;
  border: 0;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.config-item strong {
  overflow-wrap: anywhere;
}

.config-item small {
  color: var(--p-text-muted-color);
}

.config-actions {
  flex: none;
  display: flex;
}

.config-pane {
  padding-left: 1.5rem;
  border-left: 1px solid var(--p-content-border-color);
}

@media (max-width: 48rem) {
  .config-pane {
    padding: 1.25rem 0 0;
    border-left: 0;
    border-top: 1px solid var(--p-content-border-color);
  }
}
</style>
