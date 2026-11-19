<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import SideTabs from '../components/SideTabs.vue'
import { DOC_PAGES, type DocPage } from './docs/pages'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const items = computed(() => DOC_PAGES.map(({ value, icon, title }) => ({ value, icon, label: t(title) })))

// In the address, so that a page can be linked to and survives a reload
const page = computed<DocPage>({
  get: () => DOC_PAGES.find(({ value }) => value === route.query.page)?.value ?? 'home',
  set: (value) => void router.replace({ query: { ...route.query, page: value } }),
})

const content = computed(() => {
  const { versions } = DOC_PAGES.find(({ value }) => value === page.value)!
  return versions[locale.value as keyof typeof versions] ?? versions.fr
})
</script>

<template>
  <h1 class="page-title">{{ t('nav.docs') }}</h1>

  <SideTabs v-model="page" :items="items" :label="t('nav.docs')">
    <article class="doc">
      <component :is="content" @open="(target: DocPage) => (page = target)" />
    </article>
  </SideTabs>
</template>

<style scoped>
.doc {
  padding: var(--panel-padding) calc(var(--panel-padding) + 0.25rem);
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  line-height: 1.6;
}

.doc :deep(h2) {
  margin: 0 0 0.5rem;
  font-size: 1.25rem;
  font-weight: 600;
}

.doc :deep(h3) {
  margin: 1.5rem 0 0.25rem;
  font-size: 1.0625rem;
  font-weight: 600;
}

.doc :deep(p),
.doc :deep(ul),
.doc :deep(ol) {
  margin: 0.5rem 0;
}

.doc :deep(.lead) {
  color: var(--p-text-muted-color);
}

.doc :deep(:not(pre) > code) {
  padding: 0.0625rem 0.3125rem;
  background: var(--p-content-hover-background);
  border-radius: 0.25rem;
  font-size: 0.875em;
}

.doc :deep(table) {
  display: block;
  max-width: 100%;
  margin: 0.75rem 0;
  overflow-x: auto;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.doc :deep(th),
.doc :deep(td) {
  padding: 0.375rem 0.5rem;
  border-bottom: 1px solid var(--p-content-border-color);
  text-align: left;
  vertical-align: top;
}

.doc :deep(th) {
  color: var(--p-text-muted-color);
  font-weight: 500;
}

.doc :deep(td:first-child) {
  white-space: nowrap;
}

.doc :deep(.note) {
  margin: 0.75rem 0;
  padding: 0.625rem 0.875rem;
  background: light-dark(var(--p-blue-50), color-mix(in srgb, var(--p-blue-500) 12%, transparent));
  border-left: 3px solid var(--p-primary-color);
  border-radius: var(--p-content-border-radius);
}
</style>
