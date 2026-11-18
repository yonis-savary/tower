<script setup lang="ts">
import { ref, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import { JIRA_SEVERITIES, useJiraStore, type JiraIssue, type JiraTransition } from '../stores/jira'

const props = defineProps<{ issue: JiraIssue }>()

const { t } = useI18n()
const toast = useToast()
const store = useJiraStore()

const menu = useTemplateRef<InstanceType<typeof Menu>>('menu')
const items = ref<MenuItem[]>([])
const busy = ref(false)

async function open(event: MouseEvent) {
  const button = event.currentTarget as HTMLElement
  busy.value = true
  const transitions = await store.transitionsOf(props.issue.id)
  busy.value = false

  if (!transitions) {
    toast.add({ severity: 'error', summary: t('jira.transitionsError'), life: 5000 })
    return
  }

  const reachable = transitions.filter(({ status_id }) => status_id !== props.issue.status_id)
  items.value = reachable.length
    ? reachable.map((transition) => ({ label: transition.status, command: () => apply(transition) }))
    : [{ label: t('jira.noTransitionAvailable'), disabled: true }]
  // The button is given as the anchor : the click event has lost its target once Jira has answered
  menu.value?.show(event, button)
}

async function apply(transition: JiraTransition) {
  busy.value = true
  const refusal = await store.transition(props.issue.id, transition.id)
  busy.value = false

  if (refusal !== null) toast.add({ severity: 'warn', summary: `${t('jira.refused', { key: props.issue.key })} ${refusal || t('jira.transitionError')}`, life: 8000 })
}
</script>

<template>
  <button
    type="button"
    class="status-button"
    aria-haspopup="true"
    :aria-label="t('jira.changeStatus', { key: issue.key })"
    :title="t('jira.changeStatus', { key: issue.key })"
    :disabled="busy"
    @click.stop="open"
  >
    <Tag :value="issue.status" :severity="JIRA_SEVERITIES[issue.status_category]" :icon="busy ? 'pi pi-spin pi-spinner' : undefined" />
  </button>
  <Menu ref="menu" :model="items" popup />
</template>

<style scoped>
.status-button {
  flex: none;
  display: inline-flex;
  padding: 0;
  font: inherit;
  background: none;
  border: none;
  border-radius: var(--p-tag-border-radius);
  cursor: pointer;
}

.status-button:disabled {
  cursor: progress;
}

.status-button :deep(.p-tag) {
  font-size: 0.6875rem;
}

.status-button:hover :deep(.p-tag) {
  filter: brightness(0.92);
}
</style>
