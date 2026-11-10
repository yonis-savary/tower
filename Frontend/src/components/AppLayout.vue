<script setup lang="ts">
import { RouterView } from 'vue-router'
import Toast from 'primevue/toast'
import NavBar from './NavBar.vue'
import { useWorkspaceStore } from '../stores/workspace'

const workspace = useWorkspaceStore()
</script>

<template>
  <div
    :class="['app-layout', { accented: workspace.current?.accent_color }]"
    :style="workspace.current?.accent_color ? { '--workspace-accent': workspace.current.accent_color } : undefined"
  >
    <NavBar />
    <main class="app-content">
      <RouterView />
    </main>
    <Toast />
  </div>
</template>

<style scoped>
.app-layout {
  display: flex;
  min-height: 100dvh;
}

.app-layout.accented {
  border-left: 4px solid var(--workspace-accent);
}

.app-content {
  flex: 1;
  min-width: 0;
  padding: 1.5rem;
  box-sizing: border-box;
}
</style>
