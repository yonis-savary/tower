<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import { useToast } from 'primevue/usetoast'
import { listQuickActions, triggerQuickAction, type ListQuickActionsResponse } from '../client'
import { useWorkspaceStore } from '../stores/workspace'

type QuickAction = ListQuickActionsResponse[number]

const { t } = useI18n()
const toast = useToast()
const router = useRouter()
const workspace = useWorkspaceStore()

const actions = ref<ListQuickActionsResponse>([])
// Hidden while the workspace has none
const launching = ref<number | null>(null)

async function load() {
  const { data } = await listQuickActions()
  actions.value = data ?? []
}

watch(() => workspace.current?.id, (id) => { if (id) void load() }, { immediate: true })

// The result shows up in the recent events, next to this block
async function launch(action: QuickAction) {
  launching.value = action.id
  const { response } = await triggerQuickAction({ path: { action: action.id } })
  launching.value = null

  toast.add(response?.ok
    ? { severity: 'success', summary: t('events.sent', { type: action.type }), life: 3000 }
    : { severity: 'error', summary: t('events.launchError'), life: 5000 })
}

defineExpose({ load })
</script>

<template>
  <section v-if="actions.length" class="panel">
    <header class="panel-header">
      <h2>{{ t('events.quickActionsTitle') }}</h2>
      <Button :label="t('home.manage')" severity="secondary" text size="small" @click="router.push({ name: 'events' })" />
    </header>

    <ul class="actions">
      <li v-for="action in actions" :key="action.id">
        <button type="button" class="action" :disabled="launching === action.id" @click="launch(action)">
          <i :class="['pi', launching === action.id ? 'pi-spin pi-spinner' : 'pi-bolt']" aria-hidden="true" />
          <span class="action-identity">
            <strong>{{ action.name }}</strong>
            <small v-if="action.description">{{ action.description }}</small>
            <code>{{ action.type }}</code>
          </span>
        </button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.panel {
  min-width: 0;
  padding: 1.25rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.panel-header h2 {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
}

.actions {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.action {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  width: 100%;
  padding: 0.625rem 0.75rem;
  color: inherit;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}

.action:hover:not(:disabled) {
  background: var(--p-content-hover-background);
  border-color: var(--p-primary-color);
}

.action:focus-visible {
  outline: 2px solid var(--p-primary-color);
  outline-offset: 1px;
}

.action:disabled {
  cursor: progress;
}

.action .pi {
  margin-top: 0.125rem;
  color: var(--p-primary-color);
}

.action-identity {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  min-width: 0;
  overflow-wrap: anywhere;
}

.action-identity small {
  color: var(--p-text-muted-color);
  font-size: 0.8125rem;
}

.action-identity code {
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
}
</style>
