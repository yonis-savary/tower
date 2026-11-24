<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import { useToast } from 'primevue/usetoast'
import { searchWorkspace, triggerQuickAction } from '../client'
import AppIcon, { PULL_REQUEST_ICON } from './AppIcon.vue'
import WorkspaceIcon from './WorkspaceIcon.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { searchOpen } from '../utils/globalShortcuts'

// The document reads this answer from an example, which cannot tell the nullable keys nor that every key is given
type Hit = { type: string; id: number; title: string; detail: string | null; url: string | null; done: boolean }
type Result = Hit & { icon: string; workspace?: { icon: string | null; accent_color: string | null } }

const MIN_LENGTH = 2
const DEBOUNCE_MS = 200
const TYPES = ['merge_request', 'jira_issue', 'task', 'workspace', 'quick_action', 'doc_page']
const ICONS: Record<string, string> = {
  merge_request: PULL_REQUEST_ICON,
  jira_issue: 'pi pi-objects-column',
  task: 'pi pi-check-square',
  workspace: 'pi pi-th-large',
  quick_action: 'pi pi-bolt',
  doc_page: 'pi pi-book',
}

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const store = useWorkspaceStore()

const text = ref('')
const hits = ref<Hit[]>([])
const active = ref(0)
const loading = ref(false)
const list = ref<HTMLElement | null>(null)

const normalize = (value: string) => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()

const workspaceHits = computed<Result[]>(() => {
  const wanted = normalize(text.value.trim())
  if (!wanted) return []

  return (store.workspaces ?? [])
    .filter(({ name }) => normalize(name).includes(wanted))
    .map((workspace) => ({
      type: 'workspace',
      id: workspace.id,
      title: workspace.name,
      detail: workspace.id === store.current?.id ? t('search.currentWorkspace') : null,
      url: null,
      done: false,
      icon: ICONS.workspace!,
      workspace: { icon: workspace.icon, accent_color: workspace.accent_color },
    }))
})

const results = computed<Result[]>(() =>
  [...hits.value.map((hit) => ({ ...hit, icon: ICONS[hit.type] ?? 'pi pi-search' })), ...workspaceHits.value]
    .sort((a, b) => TYPES.indexOf(a.type) - TYPES.indexOf(b.type)),
)

watch(results, () => (active.value = 0))

watch(searchOpen, (open) => {
  if (!open) return
  text.value = ''
  hits.value = []
  if (store.workspaces === null) void store.loadWorkspaces()
})

let timer: ReturnType<typeof setTimeout> | undefined
let request = 0
watch(text, (value) => {
  clearTimeout(timer)
  const wanted = value.trim()
  if (wanted.length < MIN_LENGTH) {
    hits.value = []
    loading.value = false
    return
  }

  loading.value = true
  timer = setTimeout(async () => {
    const current = ++request
    const { data } = await searchWorkspace({ query: { q: wanted } })
    // An answer to an older text arriving late must not replace the newer one
    if (current !== request) return
    hits.value = (data ?? []) as Hit[]
    loading.value = false
  }, DEBOUNCE_MS)
})

function focusInput() {
  document.getElementById('global-search-input')?.focus()
}

function move(step: number) {
  if (!results.value.length) return
  active.value = (active.value + step + results.value.length) % results.value.length
  void nextTick(() => list.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' }))
}

async function open(result: Result | undefined) {
  if (!result) return
  searchOpen.value = false

  if (result.url) {
    window.open(result.url, '_blank', 'noopener,noreferrer')
    return
  }

  switch (result.type) {
    case 'task':
      return void router.push({ name: 'tasks', query: { task: result.id } })
    case 'doc_page':
      return void router.push({ name: 'documentation', query: { page: result.id } })
    case 'workspace':
      return void store.select(result.id)
    case 'quick_action': {
      const { response } = await triggerQuickAction({ path: { action: result.id } })
      toast.add(response?.ok
        ? { severity: 'success', summary: t('search.triggered', { name: result.title }), life: 3000 }
        : { severity: 'error', summary: t('search.triggerError', { name: result.title }), life: 4000 })
    }
  }
}
</script>

<template>
  <Dialog
    v-model:visible="searchOpen"
    modal
    position="top"
    :show-header="false"
    dismissable-mask
    class="global-search"
    :style="{ width: 'min(40rem, calc(100vw - 2rem))' }"
    @show="focusInput"
  >
    <div class="search-field">
      <i class="pi pi-search" aria-hidden="true" />
      <InputText
        id="global-search-input"
        v-model="text"
        :placeholder="t('search.placeholder')"
        :aria-label="t('search.placeholder')"
        role="combobox"
        aria-controls="global-search-results"
        :aria-expanded="results.length > 0"
        :aria-activedescendant="results.length ? `global-search-${active}` : undefined"
        autocomplete="off"
        fluid
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="open(results[active])"
      />
      <i v-if="loading" class="pi pi-spin pi-spinner loading" aria-hidden="true" />
    </div>

    <ul v-if="results.length" id="global-search-results" ref="list" class="results" role="listbox">
      <template v-for="(result, index) in results" :key="`${result.type}:${result.id}`">
        <li v-if="index === 0 || results[index - 1]!.type !== result.type" class="group" role="presentation">
          {{ t(`search.type.${result.type}`) }}
        </li>
        <li
          :id="`global-search-${index}`"
          role="option"
          :aria-selected="index === active"
          :class="['result', { active: index === active, done: result.done }]"
          @mousemove="active = index"
          @click="open(result)"
        >
          <WorkspaceIcon v-if="result.workspace" :icon="result.workspace.icon" :color="result.workspace.accent_color" :size="20" />
          <AppIcon v-else :icon="result.icon" class="result-icon" />
          <span class="result-text">
            <span class="result-title">{{ result.title }}</span>
            <small v-if="result.detail" class="result-detail">{{ result.detail }}</small>
          </span>
          <i v-if="result.url" class="pi pi-external-link result-external" aria-hidden="true" />
        </li>
      </template>
    </ul>
    <p v-else-if="text.trim().length >= MIN_LENGTH && !loading" class="empty">{{ t('search.empty') }}</p>
    <p v-else-if="!text.trim()" class="empty">{{ t('search.hint') }}</p>
  </Dialog>
</template>

<style scoped>
.search-field {
  position: relative;
  display: flex;
  align-items: center;
}

.search-field > .pi-search {
  position: absolute;
  left: 0.875rem;
  color: var(--p-text-muted-color);
  pointer-events: none;
}

.search-field :deep(.p-inputtext) {
  padding-left: 2.5rem;
  font-size: 1rem;
}

.loading {
  position: absolute;
  right: 0.875rem;
  color: var(--p-text-muted-color);
}

.results {
  max-height: min(60vh, 32rem);
  overflow-y: auto;
  margin: 0.75rem 0 0;
  padding: 0;
  list-style: none;
}

.group {
  padding: 0.625rem 0.5rem 0.25rem;
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.result {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem;
  border-radius: var(--p-content-border-radius);
  cursor: pointer;
}

.result.active {
  background: var(--p-highlight-background);
  color: var(--p-highlight-color);
}

.result.done .result-title {
  color: var(--p-text-muted-color);
}

.result-icon {
  flex: none;
  width: 1.25rem;
  text-align: center;
  color: var(--p-text-muted-color);
}

.result-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.result-title,
.result-detail {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.result-detail {
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
}

.result-external {
  flex: none;
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.empty {
  margin: 0.75rem 0.5rem 0.25rem;
  color: var(--p-text-muted-color);
}
</style>

<style>
/* The dialog is teleported to the body : only a global rule reaches its padding */
.global-search .p-dialog-content {
  padding: 0.75rem;
}
</style>
