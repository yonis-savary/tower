<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import type { Task } from '../stores/tasks'

defineProps<{ task: Task; showStatus?: boolean }>()
defineEmits<{ open: [] }>()

const { t } = useI18n()
</script>

<template>
  <div class="task-card" role="button" tabindex="0" @click="$emit('open')" @keydown.enter="$emit('open')">
    <span class="task-card-title">{{ task.title }}</span>
    <Tag v-if="showStatus" :value="t(`tasks.status.${task.status}`)" severity="secondary" />
  </div>
</template>

<style scoped>
.task-card {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.625rem 0.75rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  cursor: grab;
  user-select: none;
}

.task-card:hover {
  background: var(--p-content-hover-background);
}

.task-card:focus-visible {
  outline: 2px solid var(--p-primary-color);
  outline-offset: -2px;
}

.task-card-title {
  min-width: 0;
  overflow-wrap: anywhere;
}
</style>
