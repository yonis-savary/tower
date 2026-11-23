<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { createTaskNotes, previewTaskNotes, type PreviewTaskNotesResponse } from '../client'
import { useTasksStore } from '../stores/tasks'
import { useWorkspaceStore } from '../stores/workspace'
import { dedentLines, indentLines } from '../utils/textIndentation'

const PREVIEW_DELAY_MS = 300

const visible = defineModel<boolean>('visible', { required: true })
const props = defineProps<{ sprint: number | null }>()

const { t } = useI18n()
const store = useTasksStore()
const workspace = useWorkspaceStore()

type Preview = Required<PreviewTaskNotesResponse>

const text = ref('')
const sprint = ref<number | null>(null)
const preview = ref<Preview>({ tasks: [], errors: [] })
const previewing = ref(false)
const creating = ref(false)
const error = ref<string | null>(null)
const helpShown = ref(false)
const tabIndents = ref(true)

const sprintOptions = computed(() => [
  { value: null, label: t('tasks.backlog') },
  ...store.sprints.filter(({ state }) => state !== 'closed').map(({ id, name }) => ({ value: id, label: name })),
])
const draftKey = computed(() => `tower.taskNotes.${workspace.current?.id}`)
const creatable = computed(() => !previewing.value && preview.value.tasks.length > 0 && preview.value.errors.length === 0)

function readDraft(): string {
  try {
    return localStorage.getItem(draftKey.value) ?? ''
  } catch {
    return ''
  }
}

function writeDraft(value: string) {
  try {
    if (value === '') localStorage.removeItem(draftKey.value)
    else localStorage.setItem(draftKey.value, value)
  } catch {
    // Without storage the notes simply live as long as the page
  }
}

let previewTimer: ReturnType<typeof setTimeout> | undefined
let previewRequest = 0

// Immediate : the keyboard shortcut can ask for the dialog open before it is even mounted
watch(visible, (shown) => {
  if (!shown) return

  error.value = null
  tabIndents.value = true
  sprint.value = props.sprint
  text.value = readDraft()
  refreshPreview()
}, { immediate: true })

watch(text, (value) => {
  writeDraft(value)
  previewing.value = true
  clearTimeout(previewTimer)
  previewTimer = setTimeout(refreshPreview, PREVIEW_DELAY_MS)
})

async function refreshPreview() {
  clearTimeout(previewTimer)
  const request = ++previewRequest

  if (text.value.trim() === '') {
    preview.value = { tasks: [], errors: [] }
    previewing.value = false
    return
  }

  previewing.value = true
  const { data } = await previewTaskNotes({ body: { text: text.value } })
  // A slower answer to an older text must not replace the current one
  if (request !== previewRequest) return

  preview.value = { tasks: data?.tasks ?? [], errors: data?.errors ?? [] }
  previewing.value = false
}

function replaceSelection(area: HTMLTextAreaElement, start: number, end: number, replacement: string, selectStart: number, selectEnd: number) {
  area.setSelectionRange(start, end)
  // execCommand keeps the edit in the browser undo history, setRangeText does not
  if (!document.execCommand('insertText', false, replacement)) {
    area.setRangeText(replacement, start, end, 'end')
    text.value = area.value
  }
  area.setSelectionRange(selectStart, selectEnd)
}

function continueIndentation(event: KeyboardEvent) {
  const area = event.target as HTMLTextAreaElement
  const { selectionStart, selectionEnd, value } = area
  const lineStart = value.lastIndexOf('\n', selectionStart - 1) + 1
  const indentation = /^[ \t]*/.exec(value.slice(lineStart, selectionStart))![0]
  if (indentation === '') return

  event.preventDefault()
  const caret = selectionStart + 1 + indentation.length
  replaceSelection(area, selectionStart, selectionEnd, `\n${indentation}`, caret, caret)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey && !event.ctrlKey && !event.metaKey && !event.altKey && !event.isComposing) {
    continueIndentation(event)
    return
  }
  if (event.key === 'Escape' && tabIndents.value) {
    tabIndents.value = false
    event.stopPropagation()
    return
  }
  if (event.key !== 'Tab' || !tabIndents.value) return

  event.preventDefault()
  const area = event.target as HTMLTextAreaElement
  const { selectionStart, selectionEnd, value } = area

  if (!event.shiftKey && selectionStart === selectionEnd) {
    replaceSelection(area, selectionStart, selectionEnd, '\t', selectionStart + 1, selectionStart + 1)
    return
  }

  const lineStart = value.lastIndexOf('\n', selectionStart - 1) + 1
  const block = value.slice(lineStart, selectionEnd)
  const changed = event.shiftKey ? dedentLines(block) : indentLines(block)
  replaceSelection(area, lineStart, selectionEnd, changed, lineStart, lineStart + changed.length)
}

function errorLabel(line: number, code: string): string {
  return t('tasks.notes.errorLine', { line, error: t(`tasks.notes.errors.${code}`) })
}

async function create() {
  creating.value = true
  error.value = null

  const { response, data, error: refused } = await createTaskNotes({ body: { text: text.value, sprint: sprint.value } })
  creating.value = false

  if (response?.ok && data) {
    text.value = ''
    writeDraft('')
    await store.load()
    visible.value = false
    return
  }

  const errors = (refused as Partial<Preview> | undefined)?.errors
  if (errors?.length) preview.value = { tasks: (refused as Partial<Preview>).tasks ?? [], errors }
  else error.value = t('tasks.notes.createError')
}
</script>

<template>
  <Dialog v-model:visible="visible" modal :header="t('tasks.notes.title')" class="notes-dialog" :style="{ width: 'min(72rem, calc(100vw - 2rem))' }">
    <div class="notes-toolbar">
      <Button :label="t('tasks.notes.help')" icon="pi pi-question-circle" severity="secondary" text size="small" :aria-expanded="helpShown" @click="helpShown = !helpShown" />
    </div>

    <section v-if="helpShown" class="notes-help">
      <ul>
        <li>{{ t('tasks.notes.helpTitle') }}</li>
        <li>{{ t('tasks.notes.helpDescription') }}</li>
        <li>{{ t('tasks.notes.helpComment') }}</li>
        <li>{{ t('tasks.notes.helpLimit') }}</li>
        <li>{{ t('tasks.notes.helpKeyboard') }}</li>
      </ul>
      <pre>{{ t('tasks.notes.helpExample') }}</pre>
    </section>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <div class="notes-body">
      <div class="notes-editor">
        <label for="task-notes">{{ t('tasks.notes.editorLabel') }}</label>
        <Textarea
          id="task-notes"
          v-model="text"
          :placeholder="t('tasks.notes.placeholder')"
          spellcheck="true"
          autofocus
          @keydown="onKeydown"
          @focus="tabIndents = true"
        />
        <small v-if="!tabIndents" class="tab-hint">{{ t('tasks.notes.tabReleased') }}</small>
      </div>

      <section class="notes-preview" :aria-busy="previewing" aria-live="polite">
        <h3>{{ t('tasks.notes.previewTitle', { count: preview.tasks.length }, preview.tasks.length) }}</h3>
        <ul v-if="preview.errors.length" class="notes-errors">
          <li v-for="item in preview.errors" :key="`${item.line}-${item.error}`">{{ errorLabel(item.line ?? 0, item.error ?? '') }}</li>
        </ul>
        <p v-if="!preview.tasks.length" class="notes-empty">{{ t('tasks.notes.previewEmpty') }}</p>
        <ol v-else class="notes-tasks">
          <li v-for="task in preview.tasks" :key="task.line">
            <strong>{{ task.title }}</strong>
            <p v-if="task.description" class="notes-description">{{ task.description }}</p>
          </li>
        </ol>
      </section>
    </div>

    <div class="notes-actions">
      <div class="field">
        <label for="task-notes-sprint">{{ t('tasks.sprintLabel') }}</label>
        <Select v-model="sprint" input-id="task-notes-sprint" :options="sprintOptions" option-label="label" option-value="value" :placeholder="t('tasks.backlog')" class="notes-sprint" />
      </div>
      <Button :label="t('tasks.notes.create', { count: preview.tasks.length }, preview.tasks.length)" icon="pi pi-check" :disabled="!creatable" :loading="creating" @click="create" />
    </div>
  </Dialog>
</template>

<style scoped>
.notes-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-top: -0.5rem;
}

.notes-help {
  margin-bottom: 1rem;
  padding: 0.75rem 1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  font-size: 0.875rem;
}

.notes-help ul {
  margin: 0 0 0.75rem;
  padding-left: 1.25rem;
}

.notes-help pre,
.notes-editor textarea {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  tab-size: 4;
}

.notes-help pre {
  margin: 0;
  white-space: pre-wrap;
}

.notes-body {
  display: grid;
  grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
  gap: 1rem;
  margin-top: 0.5rem;
}

.notes-editor {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.notes-editor textarea {
  width: 100%;
  min-height: 18rem;
  resize: vertical;
  font-size: 0.875rem;
}

.tab-hint,
.notes-empty,
.notes-description {
  color: var(--p-text-muted-color);
}

.notes-preview {
  max-height: 26rem;
  overflow-y: auto;
  padding: 0.75rem 1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.notes-preview h3 {
  margin: 0 0 0.75rem;
  font-size: 0.9375rem;
  font-weight: 600;
}

.notes-errors {
  margin: 0 0 0.75rem;
  padding-left: 1.25rem;
  color: var(--p-red-500);
  font-size: 0.875rem;
}

.notes-tasks {
  overflow-wrap: anywhere;
  display: flex;
  flex-direction: column;
  gap: 0.625rem;
  margin: 0;
  padding-left: 1.25rem;
}

.notes-description {
  margin: 0.25rem 0 0;
  white-space: pre-wrap;
  tab-size: 4;
  font-size: 0.875rem;
}

.notes-empty {
  margin: 0;
  font-size: 0.875rem;
}

.notes-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 1rem;
}

.notes-sprint {
  min-width: 14rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

@media (max-width: 48rem) {
  .notes-body {
    grid-template-columns: minmax(0, 1fr);
  }

  .notes-editor textarea {
    min-height: 14rem;
  }
}
</style>
