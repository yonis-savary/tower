<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { createAccessKey, downloadPreconfiguredConnector, type CreateAccessKeyResponse } from '../client'
import { useWorkspaceStore } from '../stores/workspace'
import { saveFile } from '../utils/saveFile'
import { slugify } from '../utils/slugify'

const visible = defineModel<boolean>('visible', { required: true })
const emit = defineEmits<{ created: [] }>()

const { t } = useI18n()
const workspace = useWorkspaceStore()

const name = ref('')
const listen = ref('*')
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

function parseListen(value: string): string[] {
  return value.split(/[\s,]+/).filter(Boolean)
}

watch(visible, () => {
  error.value = null
  name.value = ''
  listen.value = '*'
  created.value = null
})

async function create() {
  creating.value = true
  error.value = null

  const { data } = await createAccessKey({ body: { name: name.value.trim(), listen: parseListen(listen.value) } })
  creating.value = false

  if (!data) {
    error.value = t('download.createError')
    return
  }

  created.value = data
  emit('created')
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

</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :closable="created === null"
    :close-on-escape="created === null"
    :header="created ? t('download.createdTitle') : t('download.createTab')"
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

    <template v-else>
      <p class="description">{{ t('download.configsDescription', { workspace: workspace.current?.name ?? '' }) }}</p>

      <form class="create-form" @submit.prevent="create">
        <InputText
          v-model="name"
          :placeholder="t('download.namePlaceholder')"
          :aria-label="t('download.namePlaceholder')"
          maxlength="100"
          required
          autofocus
          fluid
        />
        <Button type="submit" :label="t('download.createButton')" icon="pi pi-plus" :loading="creating" />
      </form>
      <div class="listen-field">
        <label for="config-listen">{{ t('download.listenLabel') }}</label>
        <InputText id="config-listen" v-model="listen" fluid />
        <small>{{ t('download.listenHint') }}</small>
      </div>
    </template>

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

.create-form {
  display: flex;
  gap: 0.5rem;
}

.create-form :deep(button) {
  flex: none;
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
