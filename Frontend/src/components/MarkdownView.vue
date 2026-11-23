<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { MERMAID_CLASS, renderMarkdown } from '../utils/markdown'
import { DARK_CLASS, themeMode } from '../utils/theme'

const props = defineProps<{ source: string }>()

const { t } = useI18n()
const root = ref<HTMLElement | null>(null)
let nextDiagram = 0

// renderMarkdown() escapes every raw HTML of the source : the result is safe to render
const html = computed(() => renderMarkdown(props.source))

async function drawDiagrams() {
  await nextTick()
  const placeholders = [...(root.value?.querySelectorAll<HTMLElement>(`.${MERMAID_CLASS}`) ?? [])]
  if (!placeholders.length) return

  // Only loaded by a page with a diagram : mermaid alone weighs more than the rest of the application
  const { default: mermaid } = await import('mermaid')
  const dark = document.documentElement.classList.contains(DARK_CLASS)
  mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: dark ? 'dark' : 'default' })

  for (const placeholder of placeholders) {
    const source = placeholder.dataset.source ?? placeholder.textContent ?? ''
    placeholder.dataset.source = source
    try {
      const { svg } = await mermaid.render(`doc-diagram-${nextDiagram++}`, source)
      placeholder.innerHTML = svg
      placeholder.classList.remove('failed')
    } catch (error) {
      placeholder.textContent = t('documentation.diagramError', { message: error instanceof Error ? error.message : String(error) })
      placeholder.classList.add('failed')
    }
  }
}

onMounted(drawDiagrams)
watch([html, themeMode], drawDiagrams)
</script>

<template>
  <div ref="root" class="prose markdown" v-html="html" />
</template>

<style scoped>
.markdown :deep(.mermaid-diagram) {
  margin: 0.75rem 0 1rem;
  overflow-x: auto;
  text-align: center;
  white-space: pre-wrap;
}

.markdown :deep(.mermaid-diagram.failed) {
  padding: 0.625rem 0.875rem;
  color: var(--p-red-500);
  text-align: left;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  font-size: 0.8125rem;
}
</style>
