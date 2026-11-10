<script lang="ts">
import hljs from 'highlight.js/lib/core'
import bash from 'highlight.js/lib/languages/bash'
import ini from 'highlight.js/lib/languages/ini'
import json from 'highlight.js/lib/languages/json'

// Only the languages the documentation uses : the full bundle weighs almost 1 MB
hljs.registerLanguage('bash', bash)
hljs.registerLanguage('ini', ini)
hljs.registerLanguage('json', json)

export type CodeLanguage = 'bash' | 'ini' | 'json'
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ code: string; language: CodeLanguage; filename?: string }>()

const { t } = useI18n()
const copied = ref(false)

// highlight() escapes the code : the result is safe to render as HTML
const highlighted = computed(() => hljs.highlight(props.code.trim(), { language: props.language }).value)

async function copy() {
  try {
    await navigator.clipboard.writeText(props.code.trim())
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {
    copied.value = false
  }
}
</script>

<template>
  <figure class="code-block">
    <figcaption v-if="filename">{{ filename }}</figcaption>
    <button type="button" class="card-action copy" :aria-label="t('docs.copy')" :title="t(copied ? 'docs.copied' : 'docs.copy')" @click="copy">
      <i :class="['pi', copied ? 'pi-check' : 'pi-copy']" aria-hidden="true" />
    </button>
    <pre><code class="hljs" v-html="highlighted" /></pre>
  </figure>
</template>

<style scoped>
.code-block {
  position: relative;
  margin: 0.75rem 0 1rem;
  background: var(--p-content-hover-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

figcaption {
  padding: 0.375rem 2.5rem 0.375rem 0.875rem;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-content-border-color);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.75rem;
}

.copy {
  position: absolute;
  top: 0.375rem;
  right: 0.5rem;
}

pre {
  margin: 0;
  padding: 0.75rem 2.5rem 0.75rem 0.875rem;
  overflow-x: auto;
  font-size: 0.8125rem;
  line-height: 1.55;
}

.code-block :deep(.hljs-comment) {
  color: var(--p-text-muted-color);
  font-style: italic;
}

.code-block :deep(.hljs-section),
.code-block :deep(.hljs-built_in) {
  color: light-dark(var(--p-purple-700), var(--p-purple-300));
  font-weight: 600;
}

.code-block :deep(.hljs-attr),
.code-block :deep(.hljs-keyword) {
  color: light-dark(var(--p-blue-700), var(--p-blue-300));
}

.code-block :deep(.hljs-string),
.code-block :deep(.hljs-variable),
.code-block :deep(.hljs-template-variable) {
  color: light-dark(var(--p-green-700), var(--p-green-300));
}

.code-block :deep(.hljs-number),
.code-block :deep(.hljs-literal) {
  color: light-dark(var(--p-orange-700), var(--p-orange-300));
}

.code-block :deep(.hljs-meta),
.code-block :deep(.hljs-punctuation) {
  color: var(--p-text-muted-color);
}
</style>
