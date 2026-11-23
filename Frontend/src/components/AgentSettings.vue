<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { createApiToken, listApiTokens, revokeApiToken, type ListApiTokensResponse } from '../client'
import { useWorkspaceStore } from '../stores/workspace'
import { claudeCodeCommand, mcpJsonConfig, mcpUrl } from '../utils/mcpSetup'
import CodeBlock from './CodeBlock.vue'

type Scope = 'read' | 'write'

const { t, d } = useI18n()
const workspace = useWorkspaceStore()

const tokens = ref<ListApiTokensResponse>([])
const form = ref<{ name: string; scope: Scope; expiresInDays: number | null }>({ name: '', scope: 'read', expiresInDays: 90 })
const created = ref<{ name: string; value: string } | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)

const url = mcpUrl()
const access = computed(() => workspace.current?.mcp_access ?? 'read')
const blocked = computed(() => access.value === 'blocked')

const scopes = computed(() => (['read', 'write'] as const).map((value) => ({ value, label: t(`settings.agents.scope.${value}`) })))
const expirations = computed(() =>
  [30, 90, 365, null].map((value) => ({ value, label: value ? t('settings.agents.expiresIn', { days: value }) : t('settings.agents.never') })),
)

async function load() {
  const { data } = await listApiTokens()
  tokens.value = data ?? []
}

watch(() => workspace.current?.id, (id) => { if (id) void load() }, { immediate: true })

async function create() {
  busy.value = true
  error.value = null
  const { name, scope, expiresInDays } = form.value
  const { data } = await createApiToken({ body: { name: name.trim(), scope, expires_in_days: expiresInDays } })
  busy.value = false

  if (!data?.token || !data.value) {
    error.value = t('settings.agents.createError')
    return
  }

  created.value = { name: data.token.name, value: data.value }
  form.value.name = ''
  await load()
}

async function revoke(token: ListApiTokensResponse[number]) {
  if (!window.confirm(t('settings.agents.revokeConfirm', { name: token.name }))) return

  const { response } = await revokeApiToken({ path: { token: token.id } })
  if (!response?.ok) {
    error.value = t('settings.agents.revokeError')
    return
  }

  tokens.value = tokens.value.filter(({ id }) => id !== token.id)
}

function dateOf(value: string | null | undefined): string {
  return value ? d(new Date(value), 'short') : '—'
}
</script>

<template>
  <section class="panel">
    <h2>{{ t('settings.agents.title') }}</h2>
    <p class="description">
      {{ t('settings.agents.description', { workspace: workspace.current?.name ?? '' }) }}
      <RouterLink :to="{ name: 'help', query: { page: 'agents' } }">{{ t('settings.agents.docsLink') }}</RouterLink>
    </p>

    <div class="field">
      <span class="label">{{ t('settings.agents.url') }}</span>
      <code class="url">{{ url }}</code>
    </div>

    <div class="field access">
      <span class="label">{{ t('workspaces.mcpAccess') }}</span>
      <Tag :value="t(`workspaces.mcp.${access}`)" :severity="blocked ? 'danger' : access === 'write' ? 'warn' : 'info'" />
      <small>{{ t('settings.agents.accessHint') }}</small>
    </div>
  </section>

  <section v-if="created" class="panel">
    <h2>{{ t('settings.agents.createdTitle', { name: created.name }) }}</h2>
    <Message class="panel-message" severity="warn" :closable="false">{{ t('settings.agents.createdWarning') }}</Message>
    <CodeBlock :code="created.value" language="bash" :filename="t('settings.agents.tokenLabel')" />
    <CodeBlock :code="claudeCodeCommand(url, created.value)" language="bash" filename="Claude Code" />
    <CodeBlock :code="mcpJsonConfig(url, created.value)" language="json" filename=".mcp.json" />
    <div class="actions">
      <Button :label="t('settings.agents.createdDone')" @click="created = null" />
    </div>
  </section>

  <section class="panel">
    <h2>{{ t('settings.agents.newTitle') }}</h2>

    <Message v-if="blocked" class="panel-message" severity="warn" :closable="false">{{ t('settings.agents.blocked') }}</Message>
    <Message v-if="error" class="panel-message" severity="error" :closable="false">{{ error }}</Message>

    <form class="settings-form" @submit.prevent="create">
      <div class="field">
        <label for="token-name">{{ t('settings.agents.name') }}</label>
        <InputText id="token-name" v-model="form.name" :placeholder="t('settings.agents.namePlaceholder')" maxlength="100" required fluid :disabled="blocked" />
      </div>
      <div class="field">
        <label for="token-scope">{{ t('settings.agents.scopeLabel') }}</label>
        <Select v-model="form.scope" input-id="token-scope" :options="scopes" option-label="label" option-value="value" fluid :disabled="blocked" />
        <small>{{ t('settings.agents.scopeHint') }}</small>
      </div>
      <div class="field">
        <label for="token-expiration">{{ t('settings.agents.expiration') }}</label>
        <Select v-model="form.expiresInDays" input-id="token-expiration" :options="expirations" option-label="label" option-value="value" fluid :disabled="blocked" />
      </div>
      <div class="actions">
        <Button type="submit" :label="t('settings.agents.create')" :loading="busy" :disabled="blocked" />
      </div>
    </form>
  </section>

  <section class="panel">
    <h2>{{ t('settings.agents.listTitle') }}</h2>
    <p v-if="tokens.length === 0" class="description">{{ t('settings.agents.empty') }}</p>
    <ul v-else class="tokens">
      <li v-for="token in tokens" :key="token.id">
        <div class="token-main">
          <strong>{{ token.name }}</strong>
          <Tag :value="t(`settings.agents.scope.${token.scope}`)" severity="secondary" />
        </div>
        <small>
          {{ t('settings.agents.createdAt', { date: dateOf(token.created_at) }) }} ·
          {{ t('settings.agents.lastUsed', { date: dateOf(token.last_used_at) }) }} ·
          {{ token.expires_at ? t('settings.agents.expiresAt', { date: dateOf(token.expires_at) }) : t('settings.agents.never') }}
        </small>
        <Button
          icon="pi pi-trash"
          severity="danger"
          text
          rounded
          size="small"
          class="revoke"
          :aria-label="t('settings.agents.revoke')"
          :title="t('settings.agents.revoke')"
          @click="revoke(token)"
        />
      </li>
    </ul>
  </section>
</template>

<style scoped>
.panel {
  margin-bottom: 1.25rem;
  padding: var(--panel-padding);
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel h2 {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
}

.description {
  margin: 0.25rem 0 1rem;
  color: var(--p-text-muted-color);
}

.panel h2 + .settings-form,
.panel h2 + .panel-message {
  margin-top: 1rem;
}

.panel-message {
  margin-bottom: 1rem;
}

.settings-form,
.field {
  display: flex;
  flex-direction: column;
}

.settings-form {
  gap: 1rem;
}

.field {
  align-items: flex-start;
  gap: 0.375rem;
}

.settings-form .field {
  align-items: stretch;
}

.access {
  margin-top: 1rem;
}

.label,
.field label {
  font-weight: 500;
}

.field small,
.tokens small {
  color: var(--p-text-muted-color);
}

.url {
  overflow-wrap: anywhere;
}

.actions {
  display: flex;
  justify-content: flex-end;
}

.tokens {
  display: flex;
  flex-direction: column;
  margin: 1rem 0 0;
  padding: 0;
  list-style: none;
}

.tokens li {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: center;
  gap: 0.25rem 0.75rem;
  padding: 0.625rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
}

.tokens li:last-child {
  border-bottom: 0;
}

.token-main {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.tokens small {
  grid-column: 1;
}

.revoke {
  grid-column: 2;
  grid-row: 1 / span 2;
}
</style>
