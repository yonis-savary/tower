<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import {
  createAccessKey,
  downloadPreconfiguredConnector,
  listAccessKeys,
  revokeAccessKey,
  updateAccessKeyListen,
  type CreateAccessKeyResponse,
  type ListAccessKeysResponse,
} from '../client'
import { useWorkspaceStore } from '../stores/workspace'
import { saveFile } from '../utils/saveFile'
import { slugify } from '../utils/slugify'

const visible = defineModel<boolean>('visible', { required: true })

const { t, d } = useI18n()
const workspace = useWorkspaceStore()

type AccessKey = ListAccessKeysResponse[number]

const keys = ref<ListAccessKeysResponse>([])
const tab = ref('manage')
const name = ref('')
const listen = ref('*')
// The key whose listened events are being edited
const editing = ref<number | null>(null)
const editedListen = ref('')
const savingListen = ref(false)
const creating = ref(false)
const downloading = ref(false)
const error = ref<string | null>(null)
// Holds the secret : only kept while the dialog is open, it cannot be read back
const created = ref<CreateAccessKeyResponse | null>(null)

const configJson = computed(() => JSON.stringify(created.value?.config, null, 2))
const preconfiguredFilename = computed(() => {
  const parts = ['tower', slugify(created.value?.key?.name ?? ''), slugify(workspace.current?.name ?? '')]

  return `${parts.filter(Boolean).join('-')}.pyz`
})

async function loadKeys() {
  const { data } = await listAccessKeys()
  keys.value = data ?? []
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

watch(visible, (open) => {
  error.value = null
  tab.value = 'manage'
  name.value = ''
  listen.value = '*'
  editing.value = null
  created.value = null
  if (open) void loadKeys()
})

async function create() {
  creating.value = true
  error.value = null

  const { data } = await createAccessKey({ body: { name: name.value.trim(), listen: parseListen(listen.value) } })
  creating.value = false

  if (data) created.value = data
  else error.value = t('download.createError')
}

function downloadConfig() {
  saveFile(new Blob([configJson.value], { type: 'application/json' }), 'tower-config.json')
}

async function downloadPreconfigured() {
  const id = created.value?.key?.id
  const secret = created.value?.config?.secret
  if (!id || !secret) return

  downloading.value = true
  error.value = null

  const { data } = await downloadPreconfiguredConnector({ path: { key: id }, body: { secret }, parseAs: 'blob' })
  downloading.value = false

  if (data instanceof Blob) saveFile(data, preconfiguredFilename.value)
  else error.value = t('download.connectorError')
}

async function revoke(id: number, keyName: string) {
  if (!window.confirm(t('download.revokeConfirm', { name: keyName }))) return

  const { response } = await revokeAccessKey({ path: { key: id } })
  if (!response?.ok) error.value = t('download.revokeError')
  await loadKeys()
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :closable="created === null"
    :close-on-escape="created === null"
    :header="created ? t('download.createdTitle') : t('download.configsTitle')"
    :style="{ width: '36rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <Message v-if="error" class="dialog-error" severity="error" :closable="false">{{ error }}</Message>

    <template v-if="created">
      <Message severity="warn" :closable="false">{{ t('download.secretWarning') }}</Message>
      <pre class="config">{{ configJson }}</pre>

      <div class="created-section">
        <strong>{{ t('download.preconfiguredTitle') }}</strong>
        <p>{{ t('download.preconfiguredDescription') }}</p>
        <code class="command">python3 {{ preconfiguredFilename }}</code>
      </div>
    </template>

    <Tabs v-else v-model:value="tab" class="config-tabs">
      <TabList>
        <Tab value="manage">{{ t('download.manageTab') }}</Tab>
        <Tab value="create">{{ t('download.createTab') }}</Tab>
      </TabList>
      <TabPanels>
        <TabPanel value="manage">
          <ul v-if="keys.length" class="keys">
            <li v-for="key in keys" :key="key.id">
              <div class="key-identity">
                <strong>{{ key.name }}</strong>
                <code>{{ key.client_id }}</code>
                <small>
                  {{ key.last_used_at ? t('download.lastUsed', { date: d(new Date(key.last_used_at), 'short') }) : t('download.neverUsed') }}
                </small>
                <form v-if="editing === key.id" class="listen-form" @submit.prevent="saveListen(key.id!)">
                  <InputText v-model="editedListen" :aria-label="t('download.listenLabel')" size="small" fluid autofocus />
                  <Button type="submit" :label="t('download.listenSave')" size="small" :loading="savingListen" />
                  <Button :label="t('download.listenCancel')" severity="secondary" text size="small" @click="editing = null" />
                </form>
                <small v-else class="key-listen">
                  <i class="pi pi-bell" aria-hidden="true" />
                  {{ t('download.listening', { events: listenLabel(key) }) }}
                  <Button :label="t('download.listenEdit')" link size="small" class="listen-edit" @click="editListen(key)" />
                </small>
              </div>
              <Button
                :label="t('download.revokeButton')"
                severity="danger"
                text
                size="small"
                @click="revoke(key.id!, key.name!)"
              />
            </li>
          </ul>
          <p v-else class="description">{{ t('download.empty') }}</p>
        </TabPanel>

        <TabPanel value="create">
          <p class="description">{{ t('download.configsDescription', { workspace: workspace.current?.name ?? '' }) }}</p>

          <form class="create-form" @submit.prevent="create">
            <InputText
              v-model="name"
              :placeholder="t('download.namePlaceholder')"
              :aria-label="t('download.namePlaceholder')"
              maxlength="100"
              required
              fluid
            />
            <Button type="submit" :label="t('download.createButton')" icon="pi pi-plus" :loading="creating" />
          </form>
          <div class="listen-field">
            <label for="config-listen">{{ t('download.listenLabel') }}</label>
            <InputText id="config-listen" v-model="listen" fluid />
            <small>{{ t('download.listenHint') }}</small>
          </div>
        </TabPanel>
      </TabPanels>
    </Tabs>

    <template v-if="created" #footer>
      <Button :label="t('download.closeButton')" severity="secondary" text @click="visible = false" />
      <Button :label="t('download.configButton')" severity="secondary" icon="pi pi-file" @click="downloadConfig" />
      <Button :label="t('download.preconfiguredButton')" icon="pi pi-download" :loading="downloading" @click="downloadPreconfigured" />
    </template>
  </Dialog>
</template>

<style scoped>
.dialog-error {
  margin-bottom: 1rem;
}

.description {
  margin: 0 0 1rem;
  color: var(--p-text-muted-color);
}

.config-tabs :deep(.p-tab) {
  font-family: inherit;
}

.config-tabs :deep(.p-tabpanels) {
  padding: 1rem 0 0;
}

.keys li:first-child {
  padding-top: 0;
  border-top: 0;
}

.create-form {
  display: flex;
  gap: 0.5rem;
}

.create-form :deep(button) {
  flex: none;
}

.keys {
  max-height: 16rem;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.keys li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--p-content-border-color);
}

.key-identity {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  min-width: 0;
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

.listen-field {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  margin-top: 1rem;
}

.listen-field small {
  color: var(--p-text-muted-color);
}

.config {
  margin: 1rem 0;
  padding: 0.75rem;
  overflow-x: auto;
  background: var(--p-content-hover-background);
  border-radius: var(--p-content-border-radius);
  font-size: 0.8125rem;
}

.created-section p {
  margin: 0.25rem 0 0.75rem;
  color: var(--p-text-muted-color);
}

.command {
  display: block;
  padding: 0.5rem 0.75rem;
  overflow-x: auto;
  white-space: nowrap;
  background: var(--p-content-hover-background);
  border-radius: var(--p-content-border-radius);
  font-size: 0.875rem;
}

@media (max-width: 30rem) {
  .create-form {
    flex-direction: column;
  }
}
</style>
