<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Textarea from 'primevue/textarea'
import { createJiraProfile, deleteJiraProfile, updateJiraProfile } from '../client'
import { useJiraStore, type JiraProfile } from '../stores/jira'

const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const store = useJiraStore()

const dialogVisible = ref(false)
const editing = ref<number | null>(null)
const form = ref({ name: '', board: null as number | null, jql: '' })
const busy = ref(false)
const error = ref<string | null>(null)
const formError = ref<string | null>(null)

function labelOf({ name, board_name, board }: JiraProfile): string {
  return name ?? board_name ?? t('jira.boardFallback', { board })
}

function create() {
  editing.value = null
  form.value = { name: '', board: null, jql: '' }
  formError.value = null
  dialogVisible.value = true
}

function edit(profile: JiraProfile) {
  editing.value = profile.id
  form.value = { name: profile.name ?? '', board: profile.board, jql: profile.jql ?? '' }
  formError.value = null
  dialogVisible.value = true
}

async function save() {
  busy.value = true
  formError.value = null

  const body = { name: form.value.name.trim() || null, board: form.value.board ?? 0, jql: form.value.jql.trim() || null }
  const { response } = editing.value === null
    ? await createJiraProfile({ body })
    : await updateJiraProfile({ path: { profile: editing.value }, body })
  busy.value = false

  if (!response?.ok) {
    formError.value = t('settings.jira.profiles.saveError')
    return
  }

  dialogVisible.value = false
  emit('changed')
}

async function remove(profile: JiraProfile) {
  if (!window.confirm(t('settings.jira.profiles.deleteConfirm', { name: labelOf(profile) }))) return

  error.value = null
  const { response } = await deleteJiraProfile({ path: { profile: profile.id } })
  if (!response?.ok) {
    error.value = t('settings.jira.profiles.deleteError')
    return
  }

  emit('changed')
}
</script>

<template>
  <div class="profiles">
    <div class="profiles-header">
      <p class="description">{{ t('settings.jira.profiles.description') }}</p>
      <Button :label="t('settings.jira.profiles.create')" icon="pi pi-plus" size="small" @click="create" />
    </div>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <ul v-if="store.profiles.length" class="profile-list">
      <li v-for="profile in store.profiles" :key="profile.id">
        <div class="profile-text">
          <span class="profile-name">{{ labelOf(profile) }}</span>
          <small>{{ t('settings.jira.profiles.boardNumber', { board: profile.board }) }}</small>
          <code v-if="profile.jql">{{ profile.jql }}</code>
        </div>
        <Button icon="pi pi-pencil" severity="secondary" text rounded size="small" :aria-label="t('settings.jira.profiles.edit')" :title="t('settings.jira.profiles.edit')" @click="edit(profile)" />
        <Button icon="pi pi-trash" severity="danger" text rounded size="small" :aria-label="t('settings.jira.profiles.delete')" :title="t('settings.jira.profiles.delete')" @click="remove(profile)" />
      </li>
    </ul>
    <small v-else class="empty">{{ t('settings.jira.profiles.empty') }}</small>

    <Dialog
      v-model:visible="dialogVisible"
      modal
      :header="editing === null ? t('settings.jira.profiles.create') : t('settings.jira.profiles.editing')"
      :style="{ width: 'min(32rem, calc(100vw - 2rem))' }"
    >
      <form class="profile-form" @submit.prevent="save">
        <Message v-if="formError" severity="error" :closable="false">{{ formError }}</Message>
        <div class="field">
          <label for="jira-profile-board">{{ t('settings.jira.board') }}</label>
          <InputNumber v-model="form.board" input-id="jira-profile-board" :min="1" :use-grouping="false" required autofocus fluid />
          <small>{{ t('settings.jira.boardHint') }}</small>
        </div>
        <div class="field">
          <label for="jira-profile-name">{{ t('settings.jira.profiles.name') }}</label>
          <InputText id="jira-profile-name" v-model="form.name" maxlength="100" :placeholder="t('settings.jira.profiles.namePlaceholder')" fluid />
        </div>
        <div class="field">
          <label for="jira-profile-jql">{{ t('settings.jira.profiles.jql') }}</label>
          <Textarea id="jira-profile-jql" v-model="form.jql" rows="2" maxlength="1000" auto-resize placeholder="component = Billing AND labels = backend" fluid />
          <small>{{ t('settings.jira.profiles.jqlHint') }}</small>
        </div>
        <div class="actions">
          <Button :label="t('settings.jira.profiles.cancel')" severity="secondary" text @click="dialogVisible = false" />
          <Button type="submit" :label="editing === null ? t('settings.jira.profiles.create') : t('settings.bitbucket.save')" :loading="busy" />
        </div>
      </form>
    </Dialog>
  </div>
</template>

<style scoped>
.profiles {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.profiles-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.description {
  margin: 0;
  color: var(--p-text-muted-color);
}

.profile-list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.profile-list li {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.5rem 0.625rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.profile-text {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.25rem 0.75rem;
  min-width: 0;
  margin-right: auto;
}

.profile-name {
  font-weight: 500;
}

.profile-text code {
  overflow-wrap: anywhere;
}

.profile-text small,
.empty,
.field small {
  color: var(--p-text-muted-color);
}

.profile-form,
.field {
  display: flex;
  flex-direction: column;
}

.profile-form {
  gap: 1rem;
}

.field {
  gap: 0.375rem;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>
