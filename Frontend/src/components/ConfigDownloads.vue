<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import SelectButton from 'primevue/selectbutton'
import { createConfigDownloadLink, downloadPreconfiguredConnector, type CreateAccessKeyResponse } from '../client'
import CodeBlock from './CodeBlock.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { saveFile } from '../utils/saveFile'
import { slugify } from '../utils/slugify'

type Format = 'json' | 'pyz'

/** A configuration and its secret, shown once : after a creation or a rotation */
const props = defineProps<{ created: CreateAccessKeyResponse }>()

const { t, d } = useI18n()
const workspace = useWorkspaceStore()

const downloading = ref(false)
const error = ref<string | null>(null)
const linkFormat = ref<Format>('pyz')
const creatingLink = ref(false)
const link = ref<{ format: Format; url: string; expiresAt: Date } | null>(null)

const formats = computed(() => [
  { value: 'pyz', label: t('download.preconfiguredButton') },
  { value: 'json', label: t('download.configButton') },
])
const configJson = computed(() => JSON.stringify(props.created.config, null, 2))
const preconfiguredFilename = computed(() => {
  const parts = ['tower', slugify(props.created.key?.name ?? ''), slugify(workspace.current?.name ?? '')]

  return `${parts.filter(Boolean).join('-')}.pyz`
})
const linkCommand = computed(() => {
  if (!link.value) return ''

  return link.value.format === 'pyz'
    ? `curl -fsSL -o ${preconfiguredFilename.value} '${link.value.url}'\npython3 ${preconfiguredFilename.value}`
    : `curl -fsSL -o tower-config.json '${link.value.url}'\npython3 tower-connector.pyz tower-config.json`
})

watch(
  () => props.created,
  () => {
    error.value = null
    link.value = null
  },
)

function downloadConfig() {
  saveFile(new Blob([configJson.value], { type: 'application/json' }), 'tower-config.json')
}

async function downloadPreconfigured() {
  const id = props.created.key?.id
  const secret = props.created.config?.secret
  if (!id || !secret) return

  downloading.value = true
  error.value = null

  const { data } = await downloadPreconfiguredConnector({ path: { key: id }, body: { secret }, parseAs: 'blob' })
  downloading.value = false

  if (data instanceof Blob) saveFile(data, preconfiguredFilename.value)
  else error.value = t('download.connectorError')
}

async function createLink() {
  const id = props.created.key?.id
  const secret = props.created.config?.secret
  if (!id || !secret) return

  creatingLink.value = true
  error.value = null

  const format = linkFormat.value
  const { data } = await createConfigDownloadLink({ path: { key: id }, body: { secret, format } })
  creatingLink.value = false

  if (!data?.path || !data.expires_at) {
    error.value = t('download.linkError')
    return
  }

  link.value = { format, url: `${window.location.origin}${data.path}`, expiresAt: new Date(data.expires_at) }
}
</script>

<template>
  <div class="downloads">
    <Message severity="warn" :closable="false">{{ t('download.secretWarning') }}</Message>
    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <CodeBlock :code="configJson" language="json" filename="tower-config.json" class="config" />

    <div class="buttons">
      <Button :label="t('download.configButton')" severity="secondary" icon="pi pi-file" @click="downloadConfig" />
      <Button :label="t('download.preconfiguredButton')" icon="pi pi-download" :loading="downloading" @click="downloadPreconfigured" />
    </div>

    <section class="section">
      <strong>{{ t('download.preconfiguredTitle') }}</strong>
      <p>{{ t('download.preconfiguredDescription') }}</p>
      <code class="command">python3 {{ preconfiguredFilename }}</code>
    </section>

    <section class="section">
      <strong>{{ t('download.cliTitle') }}</strong>
      <p>{{ t('download.cliDescription') }}</p>
      <div class="link-form">
        <SelectButton v-model="linkFormat" :options="formats" option-label="label" option-value="value" :allow-empty="false" size="small" :aria-label="t('download.cliTitle')" />
        <Button :label="t('download.cliButton')" icon="pi pi-link" severity="secondary" size="small" :loading="creatingLink" @click="createLink" />
      </div>
      <template v-if="link">
        <CodeBlock :code="linkCommand" language="bash" />
        <small class="link-expiry">{{ t('download.cliExpiry', { time: d(link.expiresAt, 'short') }) }}</small>
      </template>
    </section>
  </div>
</template>

<style scoped>
.downloads {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.downloads :deep(.code-block) {
  margin: 0;
}

.config :deep(pre) {
  max-height: 16rem;
}

.buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.section {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--p-content-border-color);
}

.section p {
  margin: 0;
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

.link-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.link-expiry {
  color: var(--p-text-muted-color);
}
</style>
