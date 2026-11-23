<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import { listDocPageVersions, type ListDocPageVersionsResponse, type ReadDocPageResponse } from '../client'

const visible = defineModel<boolean>('visible', { required: true })
const props = defineProps<{ page: ReadDocPageResponse; viewing: number | null }>()
const emit = defineEmits<{ select: [version: number | null] }>()

const { t, d } = useI18n()
const versions = ref<ListDocPageVersionsResponse>([])
const error = ref(false)

watch(
  () => [visible.value, props.page.id, props.page.version] as const,
  async ([shown]) => {
    if (!shown) return

    const { data } = await listDocPageVersions({ path: { page: props.page.id } })
    error.value = !data
    versions.value = data ?? []
  },
  { immediate: true },
)

function select(version: ListDocPageVersionsResponse[number]) {
  emit('select', version.version === props.page.version ? null : version.id)
  visible.value = false
}
</script>

<template>
  <Drawer v-model:visible="visible" position="right" :header="t('documentation.historyTitle')" class="doc-history">
    <p class="hint">{{ t('documentation.historyHint') }}</p>
    <Message v-if="error" severity="error" :closable="false">{{ t('documentation.loadError') }}</Message>

    <ul>
      <li v-for="version in versions" :key="version.id">
        <button type="button" :class="['version', { selected: viewing === version.id || (viewing === null && version.version === page.version) }]" @click="select(version)">
          <span class="version-title">
            <strong>{{ t('documentation.version', { version: version.version }) }}</strong>
            <Tag v-if="version.version === page.version" :value="t('documentation.current')" severity="success" />
          </span>
          <span>{{ version.title }}</span>
          <small>
            {{ (version.author as string | null) ?? t('documentation.unknownAuthor') }}
            <template v-if="version.token"> · {{ t('documentation.viaToken', { name: version.token }) }}</template>
            · {{ d(new Date(version.created_at), 'short') }}
          </small>
        </button>
      </li>
    </ul>
  </Drawer>
</template>

<style scoped>
.hint {
  margin: 0 0 1rem;
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
}

ul {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.version {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  width: 100%;
  padding: 0.5rem 0.625rem;
  color: inherit;
  background: none;
  border: 1px solid transparent;
  border-radius: var(--p-content-border-radius);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.version:hover {
  background: var(--p-content-hover-background);
}

.version.selected {
  border-color: var(--p-primary-color);
}

.version-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.version small {
  color: var(--p-text-muted-color);
}
</style>
