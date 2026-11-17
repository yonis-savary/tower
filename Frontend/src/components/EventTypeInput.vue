<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AutoComplete, { type AutoCompleteCompleteEvent, type AutoCompleteOptionSelectEvent } from 'primevue/autocomplete'
import { SUGGESTION_SOURCES, useEventSuggestionsStore } from '../stores/eventSuggestions'
import { useWorkspaceStore } from '../stores/workspace'
import { TOWER_EVENT_TYPES, patternsOf, replaceToken, sectionOf, tokenAt, tokensOf, type Token } from '../utils/eventTypes'
import { historyLabel } from '../utils/historyLine'

type Option = { value: string; label?: string }
type Group = { label: string; items: Option[] }

const model = defineModel<string>({ required: true })
const props = withDefaults(
  defineProps<{
    inputId?: string
    placeholder?: string
    ariaLabel?: string
    list?: boolean
    suggestPatterns?: boolean
    required?: boolean
    maxlength?: number
    size?: 'small'
    autofocus?: boolean
  }>(),
  { list: false, suggestPatterns: false, required: false },
)

const { t, te } = useI18n()
const workspace = useWorkspaceStore()
const store = useEventSuggestionsStore()

const root = ref<{ $el: HTMLElement } | null>(null)
const suggestions = ref<Group[]>([])
let tokenAtSearch: Token = { start: 0, end: 0, text: '' }

onMounted(() => {
  void store.load()
  if (props.autofocus) input()?.focus()
})
watch(() => workspace.current?.id, () => void store.load())

const groups = computed<Group[]>(() => {
  const towerTypes = TOWER_EVENT_TYPES.filter((type) => {
    const section = sectionOf(type)
    return !section || workspace.hasSection(section)
  })
  const groupsBySource: Group[] = [
    { label: t('eventSuggestions.tower'), items: towerTypes.map((value) => ({ value, label: historyLabel({ t, te }, value) })) },
    ...SUGGESTION_SOURCES.map((source) => ({ label: t(`eventSuggestions.${source}`), items: (store.bySource[source] ?? []).map((value) => ({ value })) })),
  ]
  const known = groupsBySource.flatMap(({ items }) => items.map(({ value }) => value))
  const all = props.suggestPatterns ? [{ label: t('eventSuggestions.pattern'), items: patternsOf(known).map((value) => ({ value })) }, ...groupsBySource] : groupsBySource

  const seen = new Set<string>()
  return all.map(({ label, items }) => ({
    label,
    items: items.filter(({ value }) => !seen.has(value) && seen.add(value)),
  }))
})

function input(): HTMLInputElement | null {
  return root.value?.$el.querySelector('input') ?? null
}

function search(event: AutoCompleteCompleteEvent) {
  const caret = input()?.selectionStart ?? model.value.length
  tokenAtSearch = props.list ? tokenAt(model.value, caret) : { start: 0, end: model.value.length, text: event.query }

  const query = tokenAtSearch.text.toLowerCase()
  const taken = props.list ? new Set(tokensOf(model.value).filter((value) => value !== tokenAtSearch.text)) : new Set<string>()
  const matches = ({ value, label }: Option) =>
    !taken.has(value) && (value.toLowerCase().includes(query) || !!label?.toLowerCase().includes(query))

  suggestions.value = groups.value.map(({ label, items }) => ({ label, items: items.filter(matches) })).filter(({ items }) => items.length)
}

// The library gives the chosen option as the new value : the text is written here instead
function update(value: unknown) {
  if (typeof value === 'string') model.value = value
}

async function select(event: AutoCompleteOptionSelectEvent) {
  const chosen = (event.value as Option).value
  model.value = props.list ? replaceToken(model.value, tokenAtSearch, chosen) : chosen

  await nextTick()
  const caret = props.list ? tokenAtSearch.start + chosen.length : chosen.length
  input()?.setSelectionRange(caret, caret)
}
</script>

<template>
  <AutoComplete
    ref="root"
    :model-value="model"
    :suggestions="suggestions"
    option-label="value"
    option-group-label="label"
    option-group-children="items"
    :input-id="inputId"
    :placeholder="placeholder"
    :size="size"
    :delay="0"
    :show-empty-message="false"
    :pt="{ pcInputText: { root: { required, maxlength, 'aria-label': ariaLabel, spellcheck: false, autocomplete: 'off' } } }"
    complete-on-focus
    fluid
    @update:model-value="update"
    @complete="search"
    @option-select="select"
  >
    <template #optiongroup="{ option }">
      <span class="group">{{ option.label }}</span>
    </template>
    <template #option="{ option }">
      <span class="option">
        <code>{{ option.value }}</code>
        <span v-if="option.label && option.label !== option.value" class="label">{{ option.label }}</span>
      </span>
    </template>
  </AutoComplete>
</template>

<style scoped>
:deep(input) {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.875rem;
}

.group {
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.option {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  width: 100%;
  min-width: 0;
}

.option code {
  font-size: 0.8125rem;
  overflow-wrap: anywhere;
}

.label {
  flex: none;
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
}
</style>
