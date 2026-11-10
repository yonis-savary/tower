<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import JiraAssignee from './JiraAssignee.vue'
import type { JiraIssue } from '../stores/jira'

const props = defineProps<{ issue: JiraIssue; taskId: number | null; creatingTask: boolean; discussing: boolean; showAssignee: boolean }>()
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
    <span class="issue-summary">{{ issue.summary }}</span>

    <footer class="issue-card-footer">
      <a :href="issue.url" target="_blank" rel="noopener noreferrer" class="issue-key" :title="t('jira.openInJira')">
        {{ issue.key }}
      </a>
      <span class="issue-actions">
        <button type="button" class="card-action" :aria-label="t('jira.copyKey')" :title="t('jira.copyKey')" @click="copyKey">
          <i class="pi pi-copy" aria-hidden="true" />
        </button>
        <button
          v-if="taskId === null"
          type="button"
          class="card-action"
          :aria-label="t('jira.createTask')"
          :title="t('jira.createTask')"
          :disabled="creatingTask"
          @click="$emit('createTask')"
        >
          <i :class="['pi', creatingTask ? 'pi-spin pi-spinner' : 'pi-plus']" aria-hidden="true" />
        </button>
        <button v-else type="button" class="card-action" :aria-label="t('jira.openTask')" :title="t('jira.openTask')" @click="$emit('openTask')">
          <i class="pi pi-check-square" aria-hidden="true" />
        </button>
        <button type="button" class="card-action" :aria-label="t('jira.discuss')" :title="t('jira.discuss')" :disabled="discussing" @click="$emit('discuss')">
          <i :class="['pi', discussing ? 'pi-spin pi-spinner' : 'pi-comments']" aria-hidden="true" />
        </button>
        <JiraAssignee v-if="showAssignee" :name="issue.assignee_name" :avatar="issue.assignee_avatar" />
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

.issue-card-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.25rem 0.5rem;
  margin-top: .5em;
}

.issue-key {
  font-size: 0.875rem;
  opacity: 0.9;
}

.issue-key .pi {
  font-size: 0.75rem;
}

.issue-summary {
  min-width: 0;
  font-size: 0.9em;
  overflow-wrap: anywhere;
  font-weight: 500;
}

.issue-actions {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  margin-left: auto;
}
</style>
