<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { useJiraStore } from '../stores/jira'
import type { Task } from '../stores/tasks'

const props = defineProps<{ task: Task; showStatus?: boolean }>()
defineEmits<{ open: [] }>()

const { t } = useI18n()
const toast = useToast()
const jira = useJiraStore()

const discussing = ref(false)

const issue = computed(() => (props.task.jira_issue === null ? undefined : jira.byId.get(props.task.jira_issue)))

// The key becomes the link to the issue where the title has it, or is put in front of the title
const title = computed(() => {
  const { title } = props.task
  if (!issue.value) return null

  const index = title.indexOf(issue.value.key)
  return index === -1
    ? { before: '', after: ` ${title}` }
    : { before: title.slice(0, index), after: title.slice(index + issue.value.key.length) }
})

async function discuss() {
  if (!issue.value) return

  discussing.value = true
  const sent = await jira.discuss(issue.value.id)
  discussing.value = false

  toast.add(sent
    ? { severity: 'success', summary: t('jira.discussSent', { key: issue.value.key }), life: 4000 }
    : { severity: 'error', summary: t('jira.discussError'), life: 5000 })
}
</script>

<template>
  <div class="task-card" role="button" tabindex="0" @click="$emit('open')" @keydown.enter="$emit('open')">
    <div class="task-card-main">
      <span class="task-card-title">
        <template v-if="issue && title">
          {{ title.before }}<a :href="issue.url" target="_blank" rel="noopener noreferrer" :title="t('jira.openInJira')" @click.stop @keydown.enter.stop>{{ issue.key }}</a>{{ title.after }}
        </template>
        <template v-else>{{ task.title }}</template>
      </span>
      <Tag v-if="showStatus" :value="t(`tasks.status.${task.status}`)" severity="secondary" />
    </div>

    <footer v-if="issue" class="task-card-footer">
      <small :title="t('tasks.issueStatus', { key: issue.key })">{{ issue.status }}</small>
      <Button
        icon="pi pi-comments"
        size="small"
        rounded
        outlined
        severity="secondary"
        class="task-card-action"
        :aria-label="t('jira.discuss')"
        :title="t('jira.discuss')"
        :loading="discussing"
        @click.stop="discuss"
        @keydown.enter.stop
      />
    </footer>
  </div>
</template>

<style scoped>
.task-card {
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

.task-card:hover {
  background: var(--p-content-hover-background);
}

.task-card:focus-visible {
  outline: 2px solid var(--p-primary-color);
  outline-offset: -2px;
}

.task-card-main,
.task-card-footer {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
}

.task-card-footer {
  align-items: center;
}

.task-card-footer small {
  min-width: 0;
  overflow: hidden;
  color: var(--p-text-muted-color);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.task-card-title {
  min-width: 0;
  overflow-wrap: anywhere;
}

.task-card-title a {
  font-weight: 600;
}

.task-card-action {
  width: 1.75rem;
  height: 1.75rem;
}
</style>
