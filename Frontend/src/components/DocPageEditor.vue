<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AutoComplete from 'primevue/autocomplete'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { createDocPage, updateDocPage, uploadDocImage, type ListDocPagesResponse, type ReadDocPageResponse } from '../client'
import MarkdownView from './MarkdownView.vue'

const MAX_IMAGE_BYTES = 5 * 1024 * 1024
const PREVIEW_DELAY_MS = 300

// Without page, the editor creates one under `parent`
const props = defineProps<{ page: ReadDocPageResponse | null; parent: number | null; pages: ListDocPagesResponse }>()
const emit = defineEmits<{ saved: [page: ReadDocPageResponse]; cancel: [] }>()

const { t } = useI18n()

const form = ref({ title: '', content: '', tags: [] as string[], parent: null as number | null })
const preview = ref('')
const tagSuggestions = ref<string[]>([])
const saving = ref(false)
const uploading = ref(false)
const error = ref<string | null>(null)
const conflict = ref(false)
const textarea = ref<{ $el: HTMLTextAreaElement } | null>(null)
const imageInput = ref<HTMLInputElement | null>(null)

watch(
  () => [props.page, props.parent] as const,
  ([page, parent]) => {
    form.value = { title: page?.title ?? '', content: page?.content ?? '', tags: [...(page?.tags ?? [])], parent: page ? page.parent : parent }
    preview.value = form.value.content
    error.value = null
    conflict.value = false
  },
  { immediate: true },
)

let previewTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => form.value.content,
  (content) => {
    clearTimeout(previewTimer)
    previewTimer = setTimeout(() => (preview.value = content), PREVIEW_DELAY_MS)
  },
)

// A page cannot move under itself or under one of its sub-pages
const descendants = computed(() => {
  const excluded = new Set<number>(props.page ? [props.page.id] : [])
  let grew = true
  while (grew) {
    grew = false
    for (const { id, parent } of props.pages) {
      if (parent !== null && excluded.has(parent) && !excluded.has(id)) {
        excluded.add(id)
        grew = true
      }
    }
  }
  return excluded
})

const parentOptions = computed(() => [
  { value: null, label: t('documentation.noParent') },
  ...props.pages.filter(({ id }) => !descendants.value.has(id)).map(({ id, title }) => ({ value: id, label: title })),
])

const knownTags = computed(() => [...new Set(props.pages.flatMap(({ tags }) => tags))].sort())

function suggestTags(event: { query: string }) {
  const query = event.query.trim().toLowerCase()
  tagSuggestions.value = knownTags.value.filter((tag) => tag.toLowerCase().includes(query) && !form.value.tags.includes(tag))
}

function readAsBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result).split(',', 2)[1] ?? '')
    reader.onerror = () => reject(reader.error)
    reader.readAsDataURL(file)
  })
}

function insertAtCursor(text: string) {
  const element = textarea.value?.$el
  const content = form.value.content
  const start = element?.selectionStart ?? content.length
  const end = element?.selectionEnd ?? content.length
  form.value.content = `${content.slice(0, start)}${text}${content.slice(end)}`
}

async function upload(file: File) {
  error.value = null
  if (file.size > MAX_IMAGE_BYTES) {
    error.value = t('documentation.imageTooLarge')
    return
  }

  uploading.value = true
  const { data } = await uploadDocImage({ body: { name: file.name || 'image', content: await readAsBase64(file) } })
  uploading.value = false

  if (data) insertAtCursor(`\n${data.markdown}\n`)
  else error.value = t('documentation.imageError')
}

function onPaste(event: ClipboardEvent) {
  const image = [...(event.clipboardData?.files ?? [])].find(({ type }) => type.startsWith('image/'))
  if (!image) return

  event.preventDefault()
  void upload(image)
}

function onImagePicked(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (file) void upload(file)
}

async function save(overwrite = false) {
  saving.value = true
  error.value = null
  conflict.value = false

  const { title, content, tags, parent } = form.value
  const body = { title: title.trim(), content, tags, parent }
  const { data, response } = props.page
    ? await updateDocPage({ path: { page: props.page.id }, body: { ...body, ...(overwrite ? {} : { version: props.page.version }) } })
    : await createDocPage({ body })
  saving.value = false

  if (data) emit('saved', data)
  else if (response?.status === 409) conflict.value = true
  else error.value = t('documentation.saveError')
}
</script>

<template>
  <form class="doc-editor" @submit.prevent="save()">
    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
    <Message v-if="conflict" severity="warn" :closable="false">
      {{ t('documentation.conflict') }}
      <Button :label="t('documentation.overwrite')" severity="warn" size="small" text @click="save(true)" />
    </Message>

    <div class="field-row">
      <div class="field title">
        <label for="doc-title">{{ t('documentation.titleLabel') }}</label>
        <InputText id="doc-title" v-model="form.title" maxlength="255" required autofocus fluid />
      </div>
      <div class="field">
        <label for="doc-parent">{{ t('documentation.parentLabel') }}</label>
        <Select v-model="form.parent" input-id="doc-parent" :options="parentOptions" option-label="label" option-value="value" :placeholder="t('documentation.noParent')" filter fluid />
      </div>
    </div>

    <div class="field">
      <label for="doc-tags">{{ t('documentation.tagsLabel') }}</label>
      <AutoComplete v-model="form.tags" input-id="doc-tags" multiple :typeahead="false" :suggestions="tagSuggestions" fluid @complete="suggestTags" />
      <small>{{ t('documentation.tagsHint') }}</small>
    </div>

    <div class="split">
      <div class="field">
        <div class="content-header">
          <label for="doc-content">{{ t('documentation.contentLabel') }}</label>
          <Button :label="t('documentation.addImage')" icon="pi pi-image" size="small" text :loading="uploading" @click="imageInput?.click()" />
          <input ref="imageInput" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden @change="onImagePicked" />
        </div>
        <Textarea id="doc-content" ref="textarea" v-model="form.content" class="content" spellcheck="true" fluid @paste="onPaste" />
        <small>{{ t('documentation.imageHint') }}</small>
      </div>
      <div class="field">
        <span class="preview-label">{{ t('documentation.preview') }}</span>
        <MarkdownView class="preview" :source="preview" />
      </div>
    </div>

    <div class="actions">
      <Button :label="t('documentation.cancel')" severity="secondary" text @click="emit('cancel')" />
      <Button type="submit" :label="t('documentation.save')" :loading="saving" />
    </div>
  </form>
</template>

<style scoped>
.doc-editor,
.field {
  display: flex;
  flex-direction: column;
}

.doc-editor {
  gap: 1rem;
}

.field {
  flex: 1;
  min-width: 0;
  gap: 0.375rem;
}

.field small,
.preview-label {
  color: var(--p-text-muted-color);
}

.field small {
  font-size: 0.8125rem;
}

.field-row {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.field-row .field {
  flex-basis: 14rem;
}

.field-row .title {
  flex-grow: 2;
}

.split {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

@media (max-width: 64rem) {
  .split {
    grid-template-columns: minmax(0, 1fr);
  }
}

.content-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  min-height: 2rem;
}

.preview-label {
  display: flex;
  align-items: center;
  min-height: 2rem;
}

.content {
  min-height: 28rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.8125rem;
  line-height: 1.55;
  resize: vertical;
}

.preview {
  min-height: 28rem;
  max-height: 70vh;
  overflow-y: auto;
  padding: 0.75rem 1rem;
  border: 1px dashed var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>
