<script setup lang="ts">
import { watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import Toast from 'primevue/toast'
import NavBar from './NavBar.vue'
import { useWorkspaceStore, type WorkspaceSection } from '../stores/workspace'

const workspace = useWorkspaceStore()
const route = useRoute()
const router = useRouter()

// A screen of a section the workspace does not use : opened by its address, or left open while switching workspace
watch(
  () => [route.meta.section as WorkspaceSection | undefined, workspace.current] as const,
  ([section, current]) => {
    if (section && current && !workspace.hasSection(section)) void router.replace({ name: 'home' })
  },
  { immediate: true },
)
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
