<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import SprintLinkIcon from './SprintLinkIcon.vue'
import { useTasksStore, type SprintLink } from '../stores/tasks'
import { linkLabel, normalizedUrl, sortedLinks } from '../utils/sprintLinks'

const props = defineProps<{ links: SprintLink[]; sprint?: number }>()

const { t } = useI18n()
const toast = useToast()
const store = useTasksStore()

const sorted = computed(() => sortedLinks(props.links))

const visible = ref(false)
const editing = ref<SprintLink | null>(null)
const form = ref({ url: '', label: '' })
const saving = ref(false)
const error = ref<string | null>(null)

function edit(link: SprintLink | null) {
  editing.value = link
  form.value = { url: link?.url ?? '', label: link?.label ?? '' }
  error.value = null
  visible.value = true
}

async function save() {
  saving.value = true
  error.value = null

  const body = { url: normalizedUrl(form.value.url), label: form.value.label.trim() || null }
  const saved = editing.value ? await store.updateLink(editing.value.id, body) : await store.addLink(props.sprint!, body)
  saving.value = false

  if (saved) visible.value = false
  else error.value = t('tasks.links.saveError')
}

async function remove(link: SprintLink) {
  if (!window.confirm(t('tasks.links.removeConfirm', { label: linkLabel(link) }))) return
  if (!(await store.removeLink(link.id))) toast.add({ severity: 'error', summary: t('tasks.links.saveError'), life: 5000 })
}
</script>

<template>
  <div v-if="sorted.length || sprint">
    <ul class="sprint-links" :aria-label="t('tasks.links.title')">
      <li v-for="link in sorted" :key="link.id" class="sprint-link">
        <a :href="link.url" target="_blank" rel="noopener noreferrer" :title="link.url">
          <SprintLinkIcon :link="link" />
          <span class="label">{{ linkLabel(link) }}</span>
        </a>
        <template v-if="sprint">
          <Button icon="pi pi-pencil" severity="secondary" text rounded size="small" :aria-label="t('tasks.links.edit')" :title="t('tasks.links.edit')" @click="edit(link)" />
          <Button icon="pi pi-times" severity="secondary" text rounded size="small" :aria-label="t('tasks.links.remove')" :title="t('tasks.links.remove')" @click="remove(link)" />
        </template>
      </li>
      <li v-if="sprint">
        <Button :label="t('tasks.links.add')" icon="pi pi-link" severity="secondary" text size="small" @click="edit(null)" />
      </li>
    </ul>

    <Dialog v-if="sprint" v-model:visible="visible" modal :header="t(editing ? 'tasks.links.edit' : 'tasks.links.add')" :style="{ width: 'min(30rem, calc(100vw - 2rem))' }">
      <form class="link-form" @submit.prevent="save">
        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
        <div class="field">
          <label :for="`link-url-${sprint}`">{{ t('tasks.links.url') }}</label>
          <InputText :id="`link-url-${sprint}`" v-model="form.url" type="text" inputmode="url" placeholder="https://" maxlength="2000" required autofocus fluid />
        </div>
        <div class="field">
          <label :for="`link-label-${sprint}`">{{ t('tasks.links.label') }}</label>
          <InputText :id="`link-label-${sprint}`" v-model="form.label" maxlength="100" fluid />
          <small>{{ t('tasks.links.labelHint') }}</small>
        </div>
        <div class="actions">
          <Button type="submit" :label="t('tasks.save')" :loading="saving" />
        </div>
      </form>
    </Dialog>
  </div>
</template>

<style scoped>
.sprint-links {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.sprint-link {
  display: flex;
  align-items: center;
  min-width: 0;
}

.sprint-link a {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  min-width: 0;
  color: var(--p-text-color);
  font-size: 0.875rem;
  text-decoration: none;
}

.sprint-link a:hover .label {
  text-decoration: underline;
}

.label {
  overflow: hidden;
  max-width: 16rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.link-form,
.field {
  display: flex;
  flex-direction: column;
}

.link-form {
  gap: 1rem;
}

.field {
  gap: 0.375rem;
}

.field small {
  color: var(--p-text-muted-color);
}

.actions {
  display: flex;
  justify-content: flex-end;
}
</style>
