<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import Tree from 'primevue/tree'
import type { TreeNode } from 'primevue/treenode'
import {
  deleteDocPage,
  listDocPages,
  readDocPage,
  readDocPageVersion,
  updateDocPage,
  type ListDocPagesResponse,
  type ReadDocPageResponse,
  type ReadDocPageVersionResponse,
} from '../client'
import DocPageEditor from '../components/DocPageEditor.vue'
import DocPageHistory from '../components/DocPageHistory.vue'
import EmptyState from '../components/EmptyState.vue'
import MarkdownView from '../components/MarkdownView.vue'
import { useAuthStore } from '../stores/auth'
import { useDeviceSocketStore, type WorkspaceSignal } from '../stores/deviceSocket'
import { useWorkspaceStore } from '../stores/workspace'

const SEARCH_DELAY_MS = 300

type Editing = { page: ReadDocPageResponse | null; parent: number | null }

const { t, d } = useI18n()
const route = useRoute()
const router = useRouter()
const workspace = useWorkspaceStore()
const socket = useDeviceSocketStore()
const auth = useAuthStore()

const pages = ref<ListDocPagesResponse>([])
const search = ref('')
const tag = ref<string | null>(null)
const includeArchived = ref(false)
const listError = ref(false)

const current = ref<ReadDocPageResponse | null>(null)
const currentError = ref(false)
const version = ref<ReadDocPageVersionResponse | null>(null)
const editing = ref<Editing | null>(null)
const historyVisible = ref(false)
const archiving = ref(false)
const deleting = ref(false)
const deleteError = ref(false)
const filtersOpen = ref(false)
const collapsed = ref(new Set<string>())

// In the address, so that a page can be linked to and survives a reload
const selectedId = computed<number | null>(() => {
  const id = Number(route.query.page)
  return Number.isInteger(id) && id > 0 ? id : null
})

function open(id: number | null) {
  editing.value = null
  void router.replace({ query: { ...route.query, page: id ?? undefined } })
}

const extraFilters = computed(() => (tag.value !== null ? 1 : 0) + (includeArchived.value ? 1 : 0))
const isAuthor = computed(() => current.value !== null && current.value.created_by === auth.user?.id)
const filtered = computed(() => search.value.trim() !== '' || tag.value !== null)
const knownTags = computed(() => [...new Set(pages.value.flatMap(({ tags }) => tags))].sort())

// A page whose parent is not listed (filtered out, or archived) shows at the top level
const nodes = computed<TreeNode[]>(() => {
  const listed = new Set(pages.value.map(({ id }) => id))
  const nodeOf = (page: ListDocPagesResponse[number]): TreeNode => ({
    key: String(page.id),
    label: page.title,
    icon: page.archived_at ? 'pi pi-inbox' : 'pi pi-file',
    styleClass: page.archived_at ? 'archived' : undefined,
    children: pages.value.filter(({ parent }) => parent === page.id).map(nodeOf),
  })
  return pages.value.filter(({ parent }) => parent === null || !listed.has(parent)).map(nodeOf)
})

const expandedKeys = computed(() =>
  Object.fromEntries(pages.value.filter(({ id }) => pages.value.some(({ parent }) => parent === id) && !collapsed.value.has(String(id))).map(({ id }) => [String(id), true])),
)

const selectionKeys = computed(() => (selectedId.value ? { [String(selectedId.value)]: true } : {}))

const editorPages = computed(() => pages.value.filter(({ archived_at }) => !archived_at))

async function loadPages() {
  const { data } = await listDocPages({
    query: { search: search.value.trim() || undefined, tag: tag.value ?? undefined, include_archived: includeArchived.value || undefined },
  })
  listError.value = !data
  pages.value = data ?? []
}

async function loadCurrent() {
  version.value = null
  currentError.value = false
  if (selectedId.value === null) {
    current.value = null
    return
  }

  const { data } = await readDocPage({ path: { page: selectedId.value } })
  currentError.value = !data
  current.value = data ?? null
}

async function showVersion(id: number | null) {
  if (id === null) {
    version.value = null
    return
  }

  const { data } = await readDocPageVersion({ path: { version: id } })
  version.value = data ?? null
}

async function toggleArchived() {
  if (!current.value) return

  archiving.value = true
  const { data } = await updateDocPage({ path: { page: current.value.id }, body: { archived: !current.value.archived_at } })
  archiving.value = false
  if (data) current.value = data
  await loadPages()
}

async function remove() {
  if (!current.value) return

  const subPages = countBelow(current.value.id)
  const message = subPages ? t('documentation.deleteConfirmBranch', { title: current.value.title, count: subPages }, subPages) : t('documentation.deleteConfirm', { title: current.value.title })
  if (!window.confirm(message)) return

  deleting.value = true
  deleteError.value = false
  const { response } = await deleteDocPage({ path: { page: current.value.id } })
  deleting.value = false

  if (!response?.ok) {
    deleteError.value = true
    return
  }
  open(null)
  await loadPages()
}

// Archived sub-pages are deleted too : they are only counted when listed
function countBelow(id: number): number {
  return pages.value.filter(({ parent }) => parent === id).reduce((count, { id: child }) => count + 1 + countBelow(child), 0)
}

async function onSaved(page: ReadDocPageResponse) {
  editing.value = null
  current.value = page
  version.value = null
  if (selectedId.value !== page.id) open(page.id)
  await loadPages()
}

function onToggle(node: TreeNode, expand: boolean) {
  const keys = new Set(collapsed.value)
  if (expand) keys.delete(node.key)
  else keys.add(node.key)
  collapsed.value = keys
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadPages, SEARCH_DELAY_MS)
})
watch([tag, includeArchived], loadPages)
watch(selectedId, () => {
  deleteError.value = false
  void loadCurrent()
}, { immediate: true })
watch(
  () => workspace.current?.id,
  (id, previous) => {
    if (previous !== undefined && id !== previous) open(null)
    void loadPages()
  },
  { immediate: true },
)

// A change made by another member or by an agent : a page being edited is left alone, the save will tell
function onSignal(signal: WorkspaceSignal) {
  if (signal.type !== 'history.created' || !signal.history_type.startsWith('doc.page.')) return

  void loadPages()
  if (!editing.value && !version.value) void loadCurrent()
}
const stopListening = socket.onSignal(onSignal)
onBeforeUnmount(() => {
  stopListening()
  clearTimeout(searchTimer)
})
</script>

<template>
  <h1 class="page-title">{{ t('nav.documentation') }}</h1>

  <div :class="['documentation', { focused: editing || selectedId }]">
    <aside class="pages">
      <div class="search-row">
        <IconField class="search">
          <InputIcon class="pi pi-search" />
          <InputText v-model="search" :placeholder="t('documentation.search')" :aria-label="t('documentation.search')" fluid />
        </IconField>
        <Button icon="pi pi-plus" :aria-label="t('documentation.newPage')" :title="t('documentation.newPage')" @click="editing = { page: null, parent: null }" />
      </div>

      <button type="button" class="filters-toggle" :aria-expanded="filtersOpen" aria-controls="doc-filters" @click="filtersOpen = !filtersOpen">
        <i :class="['pi', filtersOpen ? 'pi-chevron-down' : 'pi-chevron-right']" aria-hidden="true" />
        {{ t('documentation.moreFilters') }}
        <Tag v-if="extraFilters" :value="String(extraFilters)" rounded />
      </button>
      <div v-show="filtersOpen" id="doc-filters" class="filters">
        <Select v-model="tag" :options="knownTags" :placeholder="t('documentation.tagFilter')" :empty-message="t('documentation.noTags')" show-clear fluid />
        <label class="archived-toggle">
          <ToggleSwitch v-model="includeArchived" />
          {{ t('documentation.showArchived') }}
        </label>
      </div>

      <Message v-if="listError" severity="error" :closable="false">{{ t('documentation.loadError') }}</Message>
      <p v-else-if="!pages.length && filtered" class="no-match">{{ t('documentation.noMatch') }}</p>
      <Tree
        v-else-if="pages.length"
        :value="nodes"
        :expanded-keys="expandedKeys"
        :selection-keys="selectionKeys"
        selection-mode="single"
        class="page-tree"
        @node-select="(node: TreeNode) => open(Number(node.key))"
        @node-expand="(node: TreeNode) => onToggle(node, true)"
        @node-collapse="(node: TreeNode) => onToggle(node, false)"
      />
    </aside>

    <section class="page">
      <Button v-if="editing || selectedId" class="back" :label="t('documentation.backToPages')" icon="pi pi-arrow-left" text @click="open(null)" />

      <DocPageEditor v-if="editing" :page="editing.page" :parent="editing.parent" :pages="editorPages" @saved="onSaved" @cancel="editing = null" />

      <Message v-else-if="currentError" severity="error" :closable="false">{{ t('documentation.loadError') }}</Message>

      <article v-else-if="current" class="page-panel">
        <header class="page-header">
          <div class="page-heading">
            <h2>{{ (version ?? current).title }}</h2>
            <div v-if="(version ?? current).tags.length" class="tags">
              <Tag v-for="pageTag in (version ?? current).tags" :key="pageTag" :value="pageTag" severity="secondary" />
            </div>
            <small>
              {{ t('documentation.updated', { author: (current.updated_by as string | null) ?? t('documentation.unknownAuthor'), date: d(new Date(current.updated_at), 'short'), version: current.version }) }}
            </small>
          </div>
          <div class="page-actions">
            <Button :label="t('documentation.edit')" icon="pi pi-pencil" size="small" :disabled="!!version" @click="editing = { page: current, parent: null }" />
            <Button :label="t('documentation.newSubPage')" icon="pi pi-plus" size="small" severity="secondary" outlined @click="editing = { page: null, parent: current.id }" />
            <Button :label="t('documentation.history')" icon="pi pi-history" size="small" severity="secondary" outlined @click="historyVisible = true" />
            <Button
              :label="t(current.archived_at ? 'documentation.restore' : 'documentation.archive')"
              :icon="current.archived_at ? 'pi pi-replay' : 'pi pi-inbox'"
              size="small"
              severity="secondary"
              text
              :loading="archiving"
              @click="toggleArchived"
            />
            <Button v-if="isAuthor" :label="t('documentation.delete')" icon="pi pi-trash" size="small" severity="danger" text :loading="deleting" @click="remove" />
          </div>
        </header>

        <Message v-if="deleteError" severity="error" :closable="false">{{ t('documentation.deleteError') }}</Message>
        <Message v-if="current.archived_at" severity="secondary" :closable="false">{{ t('documentation.archived', { date: d(new Date(current.archived_at), 'short') }) }}</Message>
        <Message v-if="version" severity="info" :closable="false">
          {{ t('documentation.viewingVersion', { version: version.version, date: d(new Date(version.created_at), 'short'), author: (version.author as string | null) ?? t('documentation.unknownAuthor') }) }}
          <Button :label="t('documentation.backToCurrent')" size="small" text @click="version = null" />
        </Message>

        <MarkdownView :source="(version ?? current).content" />

        <DocPageHistory v-model:visible="historyVisible" :page="current" :viewing="version?.id ?? null" @select="showVersion" />
      </article>

      <EmptyState v-else-if="!pages.length && !filtered && !listError" icon="pi pi-book" :title="t('documentation.emptyTitle')" :hint="t('documentation.emptyHint')">
        <Button :label="t('documentation.newPage')" icon="pi pi-plus" @click="editing = { page: null, parent: null }" />
      </EmptyState>
      <EmptyState v-else icon="pi pi-file" :title="t('documentation.selectTitle')" :hint="t('documentation.selectHint')" />
    </section>
  </div>
</template>

<style scoped>
.documentation {
  display: grid;
  grid-template-columns: 16rem minmax(0, 1fr);
  align-items: start;
  gap: 1.25rem;
}

.pages {
  display: flex;
  flex-direction: column;
  gap: 0.625rem;
}

.search-row {
  display: flex;
  gap: 0.5rem;
}

.search {
  flex: 1;
  min-width: 0;
}

.filters-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0;
  color: var(--p-text-muted-color);
  background: none;
  border: 0;
  font: inherit;
  font-size: 0.875rem;
  cursor: pointer;
}

.filters-toggle:hover {
  color: var(--p-text-color);
}

.filters-toggle .pi {
  font-size: 0.75rem;
}

.filters {
  display: flex;
  flex-direction: column;
  gap: 0.625rem;
}

.archived-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
  cursor: pointer;
}

.no-match {
  margin: 0.5rem 0;
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.page-tree {
  padding: 0;
  background: none;
}

.page-tree :deep(.archived .p-tree-node-label),
.page-tree :deep(.archived .p-tree-node-icon) {
  color: var(--p-text-muted-color);
  font-style: italic;
}

.page {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.back {
  display: none;
  align-self: flex-start;
}

.page-panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: var(--panel-padding) calc(var(--panel-padding) + 0.25rem);
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.page-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem 1rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
}

.page-heading {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  min-width: 0;
}

.page-heading h2 {
  margin: 0;
  font-size: 1.375rem;
  font-weight: 600;
  overflow-wrap: anywhere;
}

.page-heading small {
  color: var(--p-text-muted-color);
}

.tags,
.page-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
}

@media (max-width: 48rem) {
  .documentation {
    grid-template-columns: minmax(0, 1fr);
  }

  /* One column on a phone : the page replaces the list, a button brings it back */
  .documentation.focused .pages {
    display: none;
  }

  .documentation:not(.focused) .page {
    display: none;
  }

  .documentation.focused .back {
    display: inline-flex;
    margin-bottom: 0.5rem;
  }
}
</style>
