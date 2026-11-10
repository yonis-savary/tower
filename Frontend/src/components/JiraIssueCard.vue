<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { JIRA_SEVERITIES, type JiraIssue } from '../stores/jira'

const props = defineProps<{ issue: JiraIssue; taskId: number | null; creatingTask: boolean; discussing: boolean }>()
defineEmits<{ createTask: []; openTask: []; discuss: [] }>()

const { t } = useI18n()
const toast = useToast()

async function copyKey() {
  try {
    await navigator.clipboard.writeText(props.issue.key)
    toast.add({ severity: 'success', summary: t('jira.keyCopied', { key: props.issue.key }), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('jira.copyError'), life: 4000 })
  }
}
</script>

<template>
  <article class="issue-card">
    <header class="issue-card-header">
      <span class="issue-key-group">
        <a :href="issue.url" target="_blank" rel="noopener noreferrer" class="issue-key" :title="t('jira.openInJira')">
          {{ issue.key }} <i class="pi pi-external-link" aria-hidden="true" />
        </a>
        <button type="button" class="copy-key" :aria-label="t('jira.copyKey')" :title="t('jira.copyKey')" @click="copyKey">
          <i class="pi pi-copy" aria-hidden="true" />
        </button>
      </span>
      <Tag :value="issue.status" :severity="JIRA_SEVERITIES[issue.status_category]" />
    </header>

    <span class="issue-summary">{{ issue.summary }}</span>

    <footer class="issue-card-footer">
      <small>{{ issue.project }} · {{ issue.issue_type }}<template v-if="issue.priority"> · {{ issue.priority }}</template></small>
      <span class="issue-actions">
        <Button
          v-if="taskId === null"
          icon="pi pi-plus"
          size="small"
          rounded
          outlined
          severity="secondary"
          :aria-label="t('jira.createTask')"
          :title="t('jira.createTask')"
          :loading="creatingTask"
          @click="$emit('createTask')"
        />
        <Button
          v-else
          icon="pi pi-check-square"
          size="small"
          rounded
          outlined
          severity="secondary"
          :aria-label="t('jira.openTask')"
          :title="t('jira.openTask')"
          @click="$emit('openTask')"
        />
        <Button
          icon="pi pi-comments"
          size="small"
          rounded
          outlined
          severity="secondary"
          :aria-label="t('jira.discuss')"
          :title="t('jira.discuss')"
          :loading="discussing"
          @click="$emit('discuss')"
        />
      </span>
    </footer>
  </article>
</template>

<style scoped>
.issue-card {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  padding: 0.625rem 0.75rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  cursor: grab;
  user-select: none;
}

.issue-card-header,
.issue-card-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.25rem 0.5rem;
}

.issue-card-footer {
  align-items: flex-end;
}

.issue-key-group {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.copy-key {
  display: inline-flex;
  padding: 0.125rem;
  color: var(--p-text-muted-color);
  background: none;
  border: 0;
  border-radius: var(--p-content-border-radius);
  cursor: pointer;
}

.copy-key:hover {
  color: var(--p-text-color);
}

.copy-key:focus-visible {
  outline: 2px solid var(--p-primary-color);
}

.copy-key .pi {
  font-size: 0.8125rem;
}

.issue-key {
  font-size: 0.875rem;
  font-weight: 600;
}

.issue-key .pi {
  font-size: 0.75rem;
}

.issue-summary {
  min-width: 0;
  overflow-wrap: anywhere;
}

.issue-card-footer small {
  flex: 1 1 8rem;
  color: var(--p-text-muted-color);
}

.issue-actions {
  display: flex;
  gap: 0.25rem;
  margin-left: auto;
}

.issue-actions :deep(.p-button) {
  width: 2rem;
  height: 2rem;
}
</style>
