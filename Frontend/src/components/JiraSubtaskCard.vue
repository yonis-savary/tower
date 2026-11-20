<script setup lang="ts">
import JiraStatusButton from './JiraStatusButton.vue'
import type { JiraIssue } from '../stores/jira'

defineProps<{ subtask: JiraIssue }>()
</script>

<template>
  <article class="subtask-card" :title="`${subtask.key} ${subtask.summary}`">
    <a :href="subtask.url" target="_blank" rel="noopener noreferrer" class="subtask-key">{{ subtask.key }}</a>
    <span class="subtask-summary">{{ subtask.summary }}</span>
    <JiraStatusButton :issue="subtask" />
  </article>
</template>

<style scoped>
.subtask-card {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  margin: -0.25rem 0 0 0.75rem;
  padding: 0.375rem 0.5rem;
  font-size: 0.8125rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-left: 3px solid var(--p-primary-color);
  border-radius: var(--p-content-border-radius);
  cursor: grab;
  user-select: none;
}

.subtask-key {
  flex: none;
  opacity: 0.9;
}

.subtask-summary {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
