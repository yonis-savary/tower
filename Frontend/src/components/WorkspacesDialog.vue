<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import ColorPicker from 'primevue/colorpicker'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import RadioButton from 'primevue/radiobutton'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import Tag from 'primevue/tag'
import {
  addWorkspaceMember,
  createWorkspace,
  deleteWorkspace,
  listWorkspaceMembers,
  removeWorkspaceIcon,
  removeWorkspaceMember,
  setWorkspaceIcon,
  updateWorkspace,
  type ListWorkspaceMembersResponse,
} from '../client'
import TowerLogo from './TowerLogo.vue'
import { useAuthStore } from '../stores/auth'
import { MCP_ACCESSES, WORKSPACE_SECTIONS, useWorkspaceStore, type McpAccess, type WorkspaceSection } from '../stores/workspace'

const visible = defineModel<boolean>('visible', { required: true })

const { t } = useI18n()
const auth = useAuthStore()
const store = useWorkspaceStore()

const selectedId = ref<number | null>(null)
const tab = ref<'general' | 'features' | 'members' | 'deletion'>('general')
const members = ref<ListWorkspaceMembersResponse>([])
const name = ref('')
const color = ref<string | null>(null)
const sections = ref<WorkspaceSection[]>([])
const mcpAccess = ref<McpAccess>('read')
const newWorkspace = ref('')
const newMember = ref('')
const deletionName = ref('')
const iconInput = ref<HTMLInputElement | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)

const selected = computed(() => store.workspaces?.find(({ id }) => id === selectedId.value) ?? null)
const isAuthor = computed(() => selected.value?.author === auth.user?.id)
const isLastWorkspace = computed(() => (store.workspaces?.length ?? 0) <= 1)
const deletionConfirmed = computed(() => deletionName.value === selected.value?.name)
const generalUnchanged = computed(() => name.value.trim() === selected.value?.name && color.value === selected.value?.accent_color)
const featuresUnchanged = computed(
  () =>
    mcpAccess.value === selected.value?.mcp_access &&
    WORKSPACE_SECTIONS.every((section) => sections.value.includes(section) === selected.value?.sections.includes(section)),
)

// The picker works without the leading #, the API with it
const pickerColor = computed({
  get: () => color.value?.slice(1) ?? '',
  set: (value: string) => (color.value = `#${value}`),
})

watch(visible, async (shown) => {
  if (!shown) return

  error.value = null
  tab.value = 'general'
  await store.loadWorkspaces()
  selectedId.value = store.current?.id ?? store.workspaces?.[0]?.id ?? null
})

watch(selected, async (workspace) => {
  name.value = workspace?.name ?? ''
  color.value = workspace?.accent_color ?? null
  sections.value = [...((workspace?.sections ?? []) as WorkspaceSection[])]
  mcpAccess.value = (workspace?.mcp_access ?? 'read') as McpAccess
  newMember.value = ''
  deletionName.value = ''
  if (tab.value === 'deletion' && !isAuthor.value) tab.value = 'general'
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

// Each tab saves its own fields only : an edit left in the other one is not sent behind the user's back
function saveGeneral() {
  return save(() => ({ name: name.value.trim(), accent_color: color.value }))
}

function saveFeatures() {
  return save((workspace) => ({ name: workspace.name, accent_color: workspace.accent_color, sections: sections.value, mcp_access: mcpAccess.value }))
}

function save(bodyOf: (workspace: NonNullable<typeof selected.value>) => { name: string; accent_color: string | null; sections?: WorkspaceSection[]; mcp_access?: McpAccess }) {
  const workspace = selected.value
  if (!workspace) return

  return run(async () => {
    const { data } = await updateWorkspace({ path: { workspace: workspace.id }, body: bodyOf(workspace) })
    if (!data) return false

    await Promise.all([store.loadWorkspaces(), store.loadCurrent()])
    return true
  }, t('workspaces.saveError'))
}

// Same limits as the server : a wrong file is told at once, without an upload
const ICON_TYPES = ['image/png', 'image/jpeg']
const ICON_MAX_SIZE = 1024 * 1024

// The icon is saved as soon as it is chosen : it has nothing to do with the form of the tab
function uploadIcon(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  const workspace = selected.value
  if (!file || !workspace) return

  if (!ICON_TYPES.includes(file.type) || file.size > ICON_MAX_SIZE) {
    error.value = t('workspaces.iconInvalid')
    return
  }

  return run(async () => {
    const { data } = await setWorkspaceIcon({ path: { workspace: workspace.id }, body: file, headers: { 'Content-Type': file.type } })
    if (!data) return false

    await Promise.all([store.loadWorkspaces(), store.loadCurrent()])
    return true
  }, t('workspaces.iconError'))
}

function removeIcon() {
  const workspace = selected.value
  if (!workspace) return

  return run(async () => {
    const { data } = await removeWorkspaceIcon({ path: { workspace: workspace.id } })
    if (!data) return false

    await Promise.all([store.loadWorkspaces(), store.loadCurrent()])
    return true
  }, t('workspaces.iconError'))
}

function removeWorkspace() {
  const workspace = selected.value
  if (!workspace || !deletionConfirmed.value) return

  return run(async () => {
    const { response } = await deleteWorkspace({ path: { workspace: workspace.id } })
    if (!response?.ok) return false

    // The server falls back on another workspace when the current one is deleted
    await Promise.all([store.loadWorkspaces(), store.loadCurrent()])
    selectedId.value = store.current?.id ?? null
    return true
  }, t('workspaces.deleteError'))
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
  <Dialog v-model:visible="visible" modal :header="t('workspaces.title')" :style="{ width: 'min(68rem, calc(100vw - 2rem))' }">
    <Message v-if="error" class="dialog-message" severity="error" :closable="false">{{ error }}</Message>

    <div class="layout">
      <aside class="workspace-list">
        <form class="inline-form workspace-create" @submit.prevent="create">
          <InputText v-model="newWorkspace" :placeholder="t('workspaces.newPlaceholder')" :aria-label="t('workspaces.newPlaceholder')" maxlength="100" required fluid />
          <Button type="submit" icon="pi pi-plus" :aria-label="t('workspaces.create')" :title="t('workspaces.create')" :loading="busy" />
        </form>

        <ul>
          <li v-for="workspace in store.workspaces ?? []" :key="workspace.id">
            <button type="button" :class="['workspace-item', { selected: workspace.id === selectedId }]" @click="selectedId = workspace.id">
              <span>{{ workspace.name }}</span>
              <Tag v-if="workspace.id === store.current?.id" :value="t('workspaces.current')" severity="secondary" />
            </button>
          </li>
        </ul>
      </aside>

      <section v-if="selected" class="workspace-detail">
        <Tabs v-model:value="tab" class="workspace-tabs">
          <TabList>
            <Tab value="general">{{ t('workspaces.tabs.general') }}</Tab>
            <Tab value="features">{{ t('workspaces.tabs.features') }}</Tab>
            <Tab value="members">{{ t('workspaces.tabs.members') }}</Tab>
            <Tab v-if="isAuthor" value="deletion">{{ t('workspaces.tabs.deletion') }}</Tab>
          </TabList>
          <TabPanels>
            <TabPanel value="general" class="tab-panel">
              <form class="settings" @submit.prevent="saveGeneral">
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
                <Button type="submit" :label="t('workspaces.save')" severity="secondary" class="save" :disabled="generalUnchanged" :loading="busy" />
              </form>

              <div class="field icon-field">
                <span class="label">{{ t('workspaces.icon') }}</span>
                <div class="icon-row">
                  <img v-if="selected.icon" :src="selected.icon" alt="" class="icon-preview" />
                  <TowerLogo v-else :color="selected.accent_color" :size="48" />
                  <small v-if="!selected.icon">{{ t('workspaces.noIcon') }}</small>
                  <input ref="iconInput" type="file" accept="image/png,image/jpeg" hidden @change="uploadIcon" />
                  <Button :label="t('workspaces.uploadIcon')" icon="pi pi-upload" severity="secondary" outlined size="small" :loading="busy" @click="iconInput?.click()" />
                  <Button v-if="selected.icon" :label="t('workspaces.removeIcon')" severity="secondary" text size="small" :disabled="busy" @click="removeIcon" />
                </div>
                <small class="hint">{{ t('workspaces.iconHint') }}</small>
              </div>

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
            </TabPanel>

            <TabPanel value="features" class="tab-panel">
              <form class="settings" @submit.prevent="saveFeatures">
                <fieldset class="field sections">
                  <legend class="label">{{ t('workspaces.sections') }}</legend>
                  <label v-for="section in WORKSPACE_SECTIONS" :key="section" class="section-option">
                    <Checkbox v-model="sections" :value="section" />
                    {{ t(`workspaces.section.${section}`) }}
                  </label>
                  <small class="hint">{{ t('workspaces.sectionsHint') }}</small>
                </fieldset>
                <fieldset class="field sections">
                  <legend class="label">{{ t('workspaces.mcpAccess') }}</legend>
                  <label v-for="access in MCP_ACCESSES" :key="access" class="section-option">
                    <RadioButton v-model="mcpAccess" :value="access" name="mcp-access" />
                    {{ t(`workspaces.mcp.${access}`) }}
                  </label>
                  <small class="hint">{{ t('workspaces.mcpAccessHint') }}</small>
                </fieldset>
                <Button type="submit" :label="t('workspaces.save')" severity="secondary" class="save" :disabled="featuresUnchanged" :loading="busy" />
              </form>
            </TabPanel>

            <TabPanel value="members" class="tab-panel">
              <form class="field member-add" @submit.prevent="addMember">
                <label for="workspace-member">{{ t('workspaces.addMember') }}</label>
                <div class="inline-form">
                  <InputText id="workspace-member" v-model="newMember" type="email" autocomplete="off" :placeholder="t('workspaces.memberPlaceholder')" required fluid />
                  <Button type="submit" :label="t('workspaces.add')" :loading="busy" />
                </div>
              </form>

              <div class="field">
                <ul class="members">
                  <li v-for="member in members" :key="member.id">
                    <i class="pi pi-user member-icon" aria-hidden="true" />
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
            </TabPanel>

            <TabPanel v-if="isAuthor" value="deletion" class="tab-panel">
              <form class="field" @submit.prevent="removeWorkspace">
                <small class="hint">{{ t('workspaces.deleteHint') }}</small>
                <Message v-if="isLastWorkspace" severity="secondary" size="small" :closable="false">{{ t('workspaces.deleteLast') }}</Message>
                <template v-else>
                  <label for="workspace-deletion">{{ t('workspaces.deleteConfirmLabel', { name: selected.name }) }}</label>
                  <div class="inline-form">
                    <InputText id="workspace-deletion" v-model="deletionName" autocomplete="off" fluid />
                    <Button type="submit" :label="t('workspaces.delete')" icon="pi pi-trash" severity="danger" :disabled="!deletionConfirmed" :loading="busy" />
                  </div>
                </template>
              </form>
            </TabPanel>
          </TabPanels>
        </Tabs>
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
  gap: 2rem;
  min-height: 28rem;
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

.workspace-create {
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
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
  min-width: 0;
}

.workspace-tabs :deep(.p-tabpanels) {
  padding: 1rem 0 0;
  background: transparent;
}

.tab-panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
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

.sections {
  margin: 0;
  padding: 0;
  border: 0;
}

.section-option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.color-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.icon-field {
  padding-top: 1rem;
  border-top: 1px solid var(--p-content-border-color);
}

.icon-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.icon-preview {
  width: 48px;
  height: 48px;
  border-radius: 22%;
  object-fit: contain;
}

.icon-row small,
.color-row small {
  color: var(--p-text-muted-color);
}

.members li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0;
}

.member-add {
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--p-content-border-color);
}

.member-icon {
  flex: none;
  display: grid;
  place-items: center;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 50%;
  background: var(--p-content-hover-background);
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
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
