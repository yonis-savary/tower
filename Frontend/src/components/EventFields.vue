<script setup lang="ts">
import { useId } from 'vue'
import { useI18n } from 'vue-i18n'
import Textarea from 'primevue/textarea'
import EventTypeInput from './EventTypeInput.vue'

const type = defineModel<string>('type', { required: true })
const payload = defineModel<string>('payload', { required: true })

const { t } = useI18n()
const id = useId()
</script>

<template>
  <div class="event-field">
    <label :for="`${id}-type`">{{ t('events.typeLabel') }}</label>
    <EventTypeInput v-model="type" :input-id="`${id}-type`" placeholder="deploy.start" :maxlength="100" required />
  </div>
  <div class="event-field">
    <label :for="`${id}-payload`">{{ t('events.payloadLabel') }}</label>
    <Textarea :id="`${id}-payload`" v-model="payload" class="event-payload" placeholder='{ "env": "prod" }' rows="4" fluid />
  </div>
</template>

<style scoped>
.event-field {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.event-payload {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.875rem;
}
</style>
