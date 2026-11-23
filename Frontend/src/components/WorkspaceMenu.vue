<script setup lang="ts">
import { useTemplateRef } from 'vue'
import Menu from 'primevue/menu'
import WorkspaceIcon from './WorkspaceIcon.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { useNavigation } from '../utils/navigation'

const workspace = useWorkspaceStore()
const { workspaceMenuItems } = useNavigation()

const menu = useTemplateRef<InstanceType<typeof Menu>>('menu')

defineExpose({ toggle: (event: Event) => menu.value?.toggle(event) })
</script>

<template>
  <Menu ref="menu" :model="workspaceMenuItems" popup class="workspace-menu" @show="workspace.loadWorkspaces">
    <template #item="{ item, props }">
      <a v-bind="props.action" class="workspace-menu-item" :aria-current="item.current ? 'true' : undefined">
        <WorkspaceIcon v-if="item.workspace" :icon="item.workspace.icon" :color="item.workspace.accent_color" :size="22" />
        <span v-else-if="item.icon" :class="item.icon" aria-hidden="true" />
        <span class="workspace-menu-label" :title="String(item.label)">{{ item.label }}</span>
        <i v-if="item.current" class="pi pi-check workspace-menu-current" aria-hidden="true" />
      </a>
    </template>
  </Menu>
</template>

<style scoped>
.workspace-menu-item {
  display: flex;
  align-items: center;
  gap: 0.625rem;
}

.workspace-menu-label {
  flex: 1;
  min-width: 0;
  max-width: 16rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.workspace-menu-current {
  color: var(--p-primary-color);
  font-size: 0.875rem;
}
</style>
