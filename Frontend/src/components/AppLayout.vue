<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterView, useRoute, useRouter } from 'vue-router'
import Toast from 'primevue/toast'
import NavBar from './NavBar.vue'
import { usePushStore } from '../stores/push'
import { useWorkspaceStore, type WorkspaceSection } from '../stores/workspace'

const workspace = useWorkspaceStore()
const push = usePushStore()
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()

// The logo already says it is Tower : the tab names the workspace and the screen
watch(
  () => [workspace.current?.name, route.meta.title as string | undefined, locale.value] as const,
  ([name, title]) => {
    document.title = [name, title && t(title)].filter(Boolean).join(' - ') || 'Tower'
  },
  { immediate: true },
)
onBeforeUnmount(() => (document.title = 'Tower'))

// A screen of a section the workspace does not use : opened by its address, or left open while switching workspace
watch(
  () => [route.meta.section as WorkspaceSection | undefined, workspace.current] as const,
  ([section, current]) => {
    if (section && current && !workspace.hasSection(section)) void router.replace({ name: 'home' })
  },
  { immediate: true },
)

// The subscription of this browser goes to the user logged in ; a click on a notification opens its screen
void push.start((url) => void router.push(url))
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

/* A column : an empty state can grow to the bottom of the page and center itself in it */
.app-content {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  padding: 1.5rem;
  box-sizing: border-box;
}
</style>
