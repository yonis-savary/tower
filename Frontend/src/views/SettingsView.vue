<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Password from 'primevue/password'
import {
  deleteBitbucketIntegration,
  deleteJiraIntegration,
  getBitbucketIntegration,
  getJiraIntegration,
  saveBitbucketIntegration,
  saveJiraIntegration,
  syncBitbucketIntegration,
  syncJiraIntegration,
  type GetBitbucketIntegrationResponse,
  type GetJiraIntegrationResponse,
} from '../client'
import AccountSettings from '../components/AccountSettings.vue'
import AgentSettings from '../components/AgentSettings.vue'
import InterfaceSettings from '../components/InterfaceSettings.vue'
import { PULL_REQUEST_ICON } from '../components/AppIcon.vue'
import SideTabs from '../components/SideTabs.vue'
import { useJiraStore } from '../stores/jira'
import { usePullRequestsStore } from '../stores/pullRequests'
import { useWorkspaceStore } from '../stores/workspace'

const { t, d } = useI18n()
const workspace = useWorkspaceStore()
const route = useRoute()
const router = useRouter()

type SettingsTab = 'account' | 'interface' | 'agents' | 'bitbucket' | 'jira' | 'clear'

const tabs = computed(() =>
  [
    { value: 'account' as const, icon: 'pi pi-user', label: t('settings.tabs.account') },
    { value: 'interface' as const, icon: 'pi pi-palette', label: t('settings.tabs.interface') },
    { value: 'agents' as const, icon: 'pi pi-sparkles', label: t('settings.tabs.agents') },
    { value: 'bitbucket' as const, icon: PULL_REQUEST_ICON, label: t('settings.tabs.bitbucket'), shown: workspace.hasSection('merge_requests') },
    { value: 'jira' as const, icon: 'pi pi-objects-column', label: t('settings.tabs.jira'), shown: workspace.hasSection('jira') },
    { value: 'clear' as const, icon: 'pi pi-trash', label: t('settings.tabs.clear') },
  ].filter(({ shown }) => shown !== false),
)

// In the address, so that a tab can be linked to and survives a reload
const tab = computed<SettingsTab>({
  get: () => tabs.value.find(({ value }) => value === route.query.tab)?.value ?? 'account',
  set: (value) => void router.replace({ query: { ...route.query, tab: value } }),
})
const jiraStore = useJiraStore()
const pullRequests = usePullRequestsStore()

const integration = ref<GetBitbucketIntegrationResponse | null>(null)
const form = ref({ workspace: '', email: '', token: '' })
const loaded = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

const jira = ref<GetJiraIntegrationResponse | null>(null)
const jiraForm = ref({ site: '', email: '', board: null as number | null, token: '' })
const jiraBusy = ref(false)
const jiraError = ref<string | null>(null)

function syncStatusOf(syncedAt: string | null | undefined): string {
  return syncedAt ? t('settings.bitbucket.lastSync', { date: d(new Date(syncedAt), 'short') }) : t('settings.bitbucket.neverSynced')
}

const syncStatus = computed(() => syncStatusOf(integration.value?.last_synced_at))
const jiraSyncStatus = computed(() => syncStatusOf(jira.value?.last_synced_at))

function show(found: GetBitbucketIntegrationResponse | null) {
  integration.value = found
  form.value = {
    workspace: found?.workspace ?? '',
    email: found?.email ?? '',
    token: '',
  }
}

function showJira(found: GetJiraIntegrationResponse | null) {
  jira.value = found
  jiraForm.value = { site: found?.site ?? '', email: found?.email ?? '', board: found?.board || null, token: '' }
}

async function load() {
  // The form stays hidden meanwhile : the answer would erase what is being typed
  loaded.value = false
  error.value = null
  jiraError.value = null
  const [bitbucket, jiraIntegration] = await Promise.all([getBitbucketIntegration(), getJiraIntegration()])
  show(bitbucket.data ?? null)
  showJira(jiraIntegration.data ?? null)
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
  if (response?.ok) {
    show(null)
    await pullRequests.load()
  }
  else error.value = t('settings.bitbucket.deleteError')
}

async function synchronizeJira() {
  jiraBusy.value = true
  jiraError.value = null

  const { data } = await syncJiraIntegration()
  jiraBusy.value = false

  if (data) jira.value = data
  else jiraError.value = t('settings.jira.syncError')
}

async function saveJira() {
  jiraBusy.value = true
  jiraError.value = null

  const { site, email, board, token } = jiraForm.value
  const { data } = await saveJiraIntegration({
    body: { site: site.trim().replace(/\/+$/, ''), email: email.trim(), board: board ?? 0, token: token || undefined },
  })
  jiraBusy.value = false

  if (!data) {
    jiraError.value = t('settings.jira.saveError')
    return
  }

  showJira(data)
  await synchronizeJira()
}

async function removeJira() {
  if (!window.confirm(t('settings.jira.deleteConfirm'))) return

  jiraError.value = null
  const { response } = await deleteJiraIntegration()
  if (response?.ok) {
    showJira(null)
    await jiraStore.load()
  }
  else jiraError.value = t('settings.jira.deleteError')
}
</script>

<template>
  <h1 class="page-title">{{ t('nav.settings') }}</h1>

  <SideTabs v-model="tab" :items="tabs" :label="t('nav.settings')">
    <AccountSettings v-if="tab === 'account'" />
    <InterfaceSettings v-if="tab === 'interface'" />
    <AgentSettings v-if="tab === 'agents'" />

    <section v-if="tab === 'bitbucket'" class="panel">
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

    <section v-if="tab === 'jira'" class="panel">
      <h2>{{ t('settings.jira.title') }}</h2>
      <p class="description">{{ t('settings.jira.description') }}</p>

      <Message v-if="jiraError" class="panel-message" severity="error" :closable="false">{{ jiraError }}</Message>
      <Message v-if="jira?.last_error" class="panel-message" severity="warn" :closable="false">{{ jira.last_error }}</Message>

      <form v-if="loaded" class="settings-form" @submit.prevent="saveJira">
        <div class="field">
          <label for="jira-site">{{ t('settings.jira.site') }}</label>
          <InputText id="jira-site" v-model="jiraForm.site" type="url" placeholder="https://mon-equipe.atlassian.net" required fluid />
        </div>
        <div class="field">
          <label for="jira-board">{{ t('settings.jira.board') }}</label>
          <InputNumber v-model="jiraForm.board" input-id="jira-board" :min="1" :use-grouping="false" required fluid />
          <small>{{ t('settings.jira.boardHint') }}</small>
        </div>
        <div class="field">
          <label for="jira-email">{{ t('settings.bitbucket.email') }}</label>
          <InputText id="jira-email" v-model="jiraForm.email" type="email" autocomplete="off" required fluid />
        </div>
        <div class="field">
          <label for="jira-token">{{ t('settings.bitbucket.token') }}</label>
          <Password
            v-model="jiraForm.token"
            input-id="jira-token"
            :feedback="false"
            :required="!jira"
            :placeholder="jira ? t('settings.bitbucket.tokenKeep') : ''"
            :input-props="{ autocomplete: 'new-password' }"
            toggle-mask
            fluid
          />
          <small>{{ t('settings.jira.tokenHint') }}</small>
        </div>

        <div class="actions">
          <small v-if="jira" class="sync-status">{{ jiraSyncStatus }}</small>
          <Button v-if="jira" :label="t('settings.bitbucket.delete')" severity="danger" text @click="removeJira" />
          <Button v-if="jira" :label="t('settings.bitbucket.sync')" icon="pi pi-refresh" severity="secondary" :loading="jiraBusy" @click="synchronizeJira" />
          <Button type="submit" :label="t('settings.bitbucket.save')" :loading="jiraBusy" />
        </div>
      </form>
    </section>

    <section v-if="tab === 'clear'" class="panel">
      <h2>{{ t('settings.clear.title') }}</h2>
      <p class="description">{{ t('settings.clear.description') }}</p>
      <div class="clear-actions">
        <Button :label="t('settings.clear.jira')" icon="pi pi-trash" severity="danger" outlined :disabled="!jira" @click="removeJira" />
        <Button :label="t('settings.clear.mergeRequests')" icon="pi pi-trash" severity="danger" outlined :disabled="!integration" @click="remove" />
      </div>
    </section>
  </SideTabs>
</template>

<style scoped>
.page-title {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.panel {
  margin-bottom: 1.25rem;
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

.clear-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
</style>
