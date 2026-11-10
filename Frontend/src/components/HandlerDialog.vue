<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'

const visible = defineModel<boolean>('visible', { required: true })

const { t } = useI18n()

const command = 'python3 tower-connector.pyz config.json --handler ~/tower/tower-handler.pyz'
const handlers = JSON.stringify({ handlers: { 'ai.discuss': '~/tower/tower-handler.pyz --cwd ~/projets' } }, null, 2)
</script>

<template>
  <Dialog v-model:visible="visible" modal :header="t('download.handlerTitle')" :style="{ width: 'min(40rem, calc(100vw - 2rem))' }">
    <p class="intro">{{ t('download.handlerIntro') }}</p>

    <section class="section">
      <strong>{{ t('download.handlerCommandTitle') }}</strong>
      <code class="command">{{ command }}</code>
    </section>

    <section class="section">
      <strong>{{ t('download.handlerConfigTitle') }}</strong>
      <p>{{ t('download.handlerConfigDescription') }}</p>
      <pre class="config">{{ handlers }}</pre>
    </section>

    <ul class="notes">
      <li>{{ t('download.handlerNoteOptions') }}</li>
      <li>{{ t('download.handlerNoteDisplay') }}</li>
      <li>{{ t('download.handlerNoteListen') }}</li>
    </ul>

    <div class="actions">
      <Button :label="t('download.handlerClose')" @click="visible = false" />
    </div>
  </Dialog>
</template>

<style scoped>
.intro,
.section p {
  margin: 0 0 0.75rem;
}

.section {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  margin-bottom: 1rem;
}

.command,
.config {
  margin: 0;
  padding: 0.5rem 0.75rem;
  overflow-x: auto;
  background: var(--p-content-hover-background);
  border-radius: var(--p-content-border-radius);
  font-size: 0.8125rem;
}

.command {
  display: block;
  white-space: nowrap;
}

.notes {
  margin: 0 0 1rem;
  padding-left: 1.25rem;
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.notes li + li {
  margin-top: 0.25rem;
}

.actions {
  display: flex;
  justify-content: flex-end;
}
</style>
