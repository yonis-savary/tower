<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import type { CreateAccessKeyResponse } from '../client'
import ConfigDownloads from './ConfigDownloads.vue'

/** The new configuration of a key whose secret just changed : shown once, it cannot be read back */
const created = defineModel<CreateAccessKeyResponse | null>({ required: true })

const { t } = useI18n()
</script>

<template>
  <Dialog
    :visible="created !== null"
    modal
    :closable="false"
    :close-on-escape="false"
    :header="t('handlers.rotatedTitle', { name: created?.key?.name ?? '' })"
    :style="{ width: '38rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <template v-if="created">
      <p class="intro">{{ t('handlers.rotatedIntro') }}</p>
      <ConfigDownloads :created="created" />
    </template>

    <template #footer>
      <Button :label="t('download.closeButton')" @click="created = null" />
    </template>
  </Dialog>
</template>

<style scoped>
.intro {
  margin: 0 0 0.75rem;
  color: var(--p-text-muted-color);
}
</style>
