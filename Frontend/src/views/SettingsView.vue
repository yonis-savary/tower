<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Password from 'primevue/password'
import {
  deleteBitbucketIntegration,
  getBitbucketIntegration,
  saveBitbucketIntegration,
  syncBitbucketIntegration,
  type GetBitbucketIntegrationResponse,
} from '../client'
import { useWorkspaceStore } from '../stores/workspace'

const { t, d } = useI18n()
const workspace = useWorkspaceStore()

const integration = ref<GetBitbucketIntegrationResponse | null>(null)
const form = ref({ workspace: '', email: '', token: '' })
const loaded = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

const syncStatus = computed(() => {
  const syncedAt = integration.value?.last_synced_at
  return syncedAt ? t('settings.bitbucket.lastSync', { date: d(new Date(syncedAt), 'short') }) : t('settings.bitbucket.neverSynced')
})

function show(found: GetBitbucketIntegrationResponse | null) {
  integration.value = found
  form.value = {
    workspace: found?.workspace ?? '',
    email: found?.email ?? '',
    token: '',
  }
}

async function load() {
  // The form stays hidden meanwhile : the answer would erase what is being typed
  loaded.value = false
  error.value = null
  const { data } = await getBitbucketIntegration()
  show(data ?? null)
  loaded.value = true
}

watch(() => workspace.current?.id, (id) => { if (id) void load() }, { immediate: true })

async function synchronize() {
  busy.value = true
  error.value = null

  const { data } = await syncBitbucketIntegration()
  busy.value = false

  if (data) integration.value = data
  else error.value = t('settings.bitbucket.syncError')
}

async function save() {
  busy.value = true
  error.value = null

  const { workspace: bitbucketWorkspace, email, token } = form.value
  const { data } = await saveBitbucketIntegration({
    body: { workspace: bitbucketWorkspace.trim(), email: email.trim(), token: token || undefined },
  })
  busy.value = false

  if (!data) {
    error.value = t('settings.bitbucket.saveError')
    return
  }

  show(data)
  await synchronize()
}

async function remove() {
  if (!window.confirm(t('settings.bitbucket.deleteConfirm'))) return

  error.value = null
  const { response } = await deleteBitbucketIntegration()
  if (response?.ok) show(null)
  else error.value = t('settings.bitbucket.deleteError')
}
</script>

<template>
  <h1 class="page-title">{{ t('nav.settings') }}</h1>

  <section class="panel">
    <h2>{{ t('settings.bitbucket.title') }}</h2>
    <p class="description">{{ t('settings.bitbucket.description') }}</p>

    <Message v-if="error" class="panel-message" severity="error" :closable="false">{{ error }}</Message>
    <Message v-if="integration?.last_error" class="panel-message" severity="warn" :closable="false">{{ integration.last_error }}</Message>

    <form v-if="loaded" class="settings-form" @submit.prevent="save">
      <div class="field">
        <label for="bitbucket-workspace">{{ t('settings.bitbucket.workspace') }}</label>
        <InputText id="bitbucket-workspace" v-model="form.workspace" placeholder="mon-equipe" required fluid />
      </div>
      <div class="field">
        <label for="bitbucket-email">{{ t('settings.bitbucket.email') }}</label>
        <InputText id="bitbucket-email" v-model="form.email" type="email" autocomplete="off" required fluid />
      </div>
      <div class="field">
        <label for="bitbucket-token">{{ t('settings.bitbucket.token') }}</label>
        <Password
          v-model="form.token"
          input-id="bitbucket-token"
          :feedback="false"
          :required="!integration"
          :placeholder="integration ? t('settings.bitbucket.tokenKeep') : ''"
          :input-props="{ autocomplete: 'new-password' }"
          toggle-mask
          fluid
        />
        <small>{{ t('settings.bitbucket.tokenHint') }}</small>
      </div>

      <div class="actions">
        <small v-if="integration" class="sync-status">{{ syncStatus }}</small>
        <Button v-if="integration" :label="t('settings.bitbucket.delete')" severity="danger" text @click="remove" />
        <Button v-if="integration" :label="t('settings.bitbucket.sync')" icon="pi pi-refresh" severity="secondary" :loading="busy" @click="synchronize" />
        <Button type="submit" :label="t('settings.bitbucket.save')" :loading="busy" />
      </div>
    </form>
  </section>
</template>

<style scoped>
.page-title {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.panel {
  max-width: 48rem;
  padding: 1.25rem;
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
  gap: 0.375rem;
}

.field small,
.sync-status {
  color: var(--p-text-muted-color);
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
}

.sync-status {
  margin-right: auto;
}
</style>
