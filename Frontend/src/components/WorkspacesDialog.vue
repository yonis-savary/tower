<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import ColorPicker from 'primevue/colorpicker'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import {
  addWorkspaceMember,
  createWorkspace,
  listWorkspaceMembers,
  removeWorkspaceMember,
  updateWorkspace,
  type ListWorkspaceMembersResponse,
} from '../client'
import { useAuthStore } from '../stores/auth'
import { useWorkspaceStore } from '../stores/workspace'

const visible = defineModel<boolean>('visible', { required: true })

const { t } = useI18n()
const auth = useAuthStore()
const store = useWorkspaceStore()

const selectedId = ref<number | null>(null)
const members = ref<ListWorkspaceMembersResponse>([])
const name = ref('')
const color = ref<string | null>(null)
const newWorkspace = ref('')
const newMember = ref('')
const busy = ref(false)
const error = ref<string | null>(null)

const selected = computed(() => store.workspaces?.find(({ id }) => id === selectedId.value) ?? null)
const unchanged = computed(() => name.value.trim() === selected.value?.name && color.value === selected.value?.accent_color)

// The picker works without the leading #, the API with it
const pickerColor = computed({
  get: () => color.value?.slice(1) ?? '',
  set: (value: string) => (color.value = `#${value}`),
})

watch(visible, async (shown) => {
  if (!shown) return

  error.value = null
  await store.loadWorkspaces()
  selectedId.value = store.current?.id ?? store.workspaces?.[0]?.id ?? null
})

watch(selected, async (workspace) => {
  name.value = workspace?.name ?? ''
  color.value = workspace?.accent_color ?? null
  newMember.value = ''
  members.value = []
  if (!workspace) return

  const { data } = await listWorkspaceMembers({ path: { workspace: workspace.id } })
  members.value = data ?? []
})

async function run(action: () => Promise<boolean>, failure: string) {
  busy.value = true
  error.value = null
  const succeeded = await action()
  busy.value = false
  if (!succeeded) error.value = failure
}

function create() {
  return run(async () => {
    const { data } = await createWorkspace({ body: { name: newWorkspace.value.trim() } })
    if (!data) return false

    newWorkspace.value = ''
    await store.loadWorkspaces()
    selectedId.value = data.id
    return true
  }, t('workspaces.createError'))
}

function save() {
  const workspace = selected.value
  if (!workspace) return

  return run(async () => {
    const { data } = await updateWorkspace({ path: { workspace: workspace.id }, body: { name: name.value.trim(), accent_color: color.value } })
    if (!data) return false

    await Promise.all([store.loadWorkspaces(), store.loadCurrent()])
    return true
  }, t('workspaces.saveError'))
}

function addMember() {
  const workspace = selected.value
  if (!workspace) return

  return run(async () => {
    const { data } = await addWorkspaceMember({ path: { workspace: workspace.id }, body: { email: newMember.value.trim() } })
    if (!data) return false

    newMember.value = ''
    const { data: list } = await listWorkspaceMembers({ path: { workspace: workspace.id } })
    members.value = list ?? members.value
    return true
  }, t('workspaces.addMemberError'))
}

function removeMember(member: ListWorkspaceMembersResponse[number]) {
  const workspace = selected.value
  if (!workspace) return

  const leaving = member.id === auth.user?.id
  const question = leaving ? t('workspaces.leaveConfirm', { name: workspace.name }) : t('workspaces.removeConfirm', { email: member.email, name: workspace.name })
  if (!window.confirm(question)) return

  return run(async () => {
    const { response } = await removeWorkspaceMember({ path: { workspace: workspace.id, user: member.id } })
    if (!response?.ok) return false

    members.value = members.value.filter(({ id }) => id !== member.id)
    if (!leaving) return true

    // The server falls back on another workspace when the current one is left
    await Promise.all([store.loadWorkspaces(), store.loadCurrent()])
    selectedId.value = store.current?.id ?? null
    return true
  }, t('workspaces.removeError'))
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :header="t('workspaces.title')" :style="{ width: 'min(52rem, calc(100vw - 2rem))' }">
    <Message v-if="error" class="dialog-message" severity="error" :closable="false">{{ error }}</Message>

    <div class="layout">
      <aside class="workspace-list">
        <ul>
          <li v-for="workspace in store.workspaces ?? []" :key="workspace.id">
            <button type="button" :class="['workspace-item', { selected: workspace.id === selectedId }]" @click="selectedId = workspace.id">
              <span>{{ workspace.name }}</span>
              <Tag v-if="workspace.id === store.current?.id" :value="t('workspaces.current')" severity="secondary" />
            </button>
          </li>
        </ul>

        <form class="inline-form" @submit.prevent="create">
          <InputText v-model="newWorkspace" :placeholder="t('workspaces.newPlaceholder')" :aria-label="t('workspaces.newPlaceholder')" maxlength="100" required fluid />
          <Button type="submit" icon="pi pi-plus" :aria-label="t('workspaces.create')" :title="t('workspaces.create')" :loading="busy" />
        </form>
      </aside>

      <section v-if="selected" class="workspace-detail">
        <form class="settings" @submit.prevent="save">
          <div class="field">
            <label for="workspace-name">{{ t('workspaces.name') }}</label>
            <InputText id="workspace-name" v-model="name" maxlength="100" required fluid />
          </div>
          <div class="field">
            <span class="label">{{ t('workspaces.color') }}</span>
            <div class="color-row">
              <ColorPicker v-model="pickerColor" format="hex" :aria-label="t('workspaces.color')" />
              <code v-if="color">{{ color }}</code>
              <small v-else>{{ t('workspaces.noColor') }}</small>
              <Button v-if="color" :label="t('workspaces.removeColor')" severity="secondary" text size="small" @click="color = null" />
            </div>
            <small class="hint">{{ t('workspaces.colorHint') }}</small>
          </div>
          <Button type="submit" :label="t('workspaces.save')" severity="secondary" class="save" :disabled="unchanged" :loading="busy" />
        </form>

        <Button
          v-if="selected.id !== store.current?.id"
          :label="t('workspaces.switch')"
          icon="pi pi-arrow-right-arrow-left"
          severity="secondary"
          outlined
          size="small"
          class="switch"
          @click="store.select(selected.id)"
        />

        <div class="field">
          <span class="label">{{ t('workspaces.members') }}</span>
          <ul class="members">
            <li v-for="member in members" :key="member.id">
              <span class="member-email">{{ member.email }}</span>
              <small v-if="member.id === auth.user?.id">{{ t('workspaces.you') }}</small>
              <Button
                icon="pi pi-times"
                severity="secondary"
                text
                rounded
                size="small"
                :disabled="members.length <= 1"
                :aria-label="t(member.id === auth.user?.id ? 'workspaces.leave' : 'workspaces.remove')"
                :title="t(member.id === auth.user?.id ? 'workspaces.leave' : 'workspaces.remove')"
                @click="removeMember(member)"
              />
            </li>
          </ul>
          <small class="hint">{{ t('workspaces.membersHint') }}</small>
        </div>

        <form class="field" @submit.prevent="addMember">
          <label for="workspace-member">{{ t('workspaces.addMember') }}</label>
          <div class="inline-form">
            <InputText id="workspace-member" v-model="newMember" type="email" autocomplete="off" :placeholder="t('workspaces.memberPlaceholder')" required fluid />
            <Button type="submit" :label="t('workspaces.add')" :loading="busy" />
          </div>
        </form>
      </section>
    </div>
  </Dialog>
</template>

<style scoped>
.dialog-message {
  margin-bottom: 1rem;
}

.layout {
  display: grid;
  grid-template-columns: minmax(12rem, 16rem) 1fr;
  gap: 1.25rem;
}

@media (max-width: 40rem) {
  .layout {
    grid-template-columns: 1fr;
  }
}

.workspace-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.workspace-list ul,
.members {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.workspace-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  width: 100%;
  padding: 0.5rem 0.625rem;
  color: var(--p-text-color);
  background: transparent;
  border: 0;
  border-radius: var(--p-content-border-radius);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.workspace-item span {
  min-width: 0;
  overflow-wrap: anywhere;
}

.workspace-item:hover {
  background: var(--p-content-hover-background);
}

.workspace-item.selected {
  background: var(--p-highlight-background);
  color: var(--p-highlight-color);
}

.workspace-detail {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.label {
  font-weight: 500;
}

.inline-form {
  display: flex;
  gap: 0.5rem;
}

.switch,
.save {
  align-self: flex-start;
}

.settings {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.color-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.color-row small {
  color: var(--p-text-muted-color);
}

.members li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0;
}

.member-email {
  min-width: 0;
  overflow-wrap: anywhere;
}

.members li small,
.hint {
  color: var(--p-text-muted-color);
}

.members li :deep(.p-button) {
  margin-left: auto;
}
</style>
