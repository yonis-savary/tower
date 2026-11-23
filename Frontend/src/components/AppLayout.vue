<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterView, useRoute, useRouter } from 'vue-router'
import Toast from 'primevue/toast'
import MobileNav from './MobileNav.vue'
import NavBar from './NavBar.vue'
import WorkspacesDialog from './WorkspacesDialog.vue'
import { useDeviceSocketStore } from '../stores/deviceSocket'
import { useJiraStore } from '../stores/jira'
import { usePullRequestsStore } from '../stores/pullRequests'
import { usePushStore } from '../stores/push'
import { useTasksStore } from '../stores/tasks'
import { useWorkspaceStore, type WorkspaceSection } from '../stores/workspace'
import { useMediaQuery } from '../utils/mediaQuery'
import { useNavigation } from '../utils/navigation'
import { useGlobalShortcuts } from '../utils/globalShortcuts'

const workspace = useWorkspaceStore()
const push = usePushStore()
const deviceSocket = useDeviceSocketStore()
const tasks = useTasksStore()
const jira = useJiraStore()
const pullRequests = usePullRequestsStore()
const { workspacesDialogVisible } = useNavigation()

// Same width as the media queries of the screens (see style.css) : a phone gets its bars instead of the sidebar
const phone = useMediaQuery('(max-width: 48rem)')

onMounted(workspace.loadCurrent)
useGlobalShortcuts()

// Loaded with the page rather than with each screen, for the counters of the navigation
watch(
  () => [workspace.current?.id, workspace.current?.sections] as const,
  ([id]) => {
    if (!id) return
    if (workspace.hasSection('tasks')) void tasks.load()
    if (workspace.hasSection('jira')) void jira.load()
    if (workspace.hasSection('merge_requests')) void pullRequests.load()
  },
)

// Follows the selected workspace, and stops when the logout clears it
watch(
  () => workspace.current?.id,
  (id) => (id ? deviceSocket.connect() : deviceSocket.disconnect()),
)
onBeforeUnmount(deviceSocket.disconnect)
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

// The mobile browser bar takes the accent of the workspace ; index.html holds the color of Tower
const themeColor = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')
const towerColor = themeColor?.content ?? null
watch(
  () => workspace.current?.accent_color,
  (accent) => themeColor?.setAttribute('content', accent || towerColor || ''),
  { immediate: true },
)
onBeforeUnmount(() => themeColor?.setAttribute('content', towerColor ?? ''))

// The tab shows the icon of the workspace (its image, else the logo in its color) : the icons of index.html step aside meanwhile
const towerIcons = [...document.querySelectorAll<HTMLLinkElement>('link[rel="icon"]')]
const workspaceIcon = document.createElement('link')
workspaceIcon.rel = 'icon'
watch(
  () => workspace.current?.favicon_url,
  (url) => {
    if (!url) {
      workspaceIcon.remove()
      towerIcons.forEach((icon) => document.head.append(icon))
      return
    }

    towerIcons.forEach((icon) => icon.remove())
    workspaceIcon.href = url
    document.head.append(workspaceIcon)
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  workspaceIcon.remove()
  towerIcons.forEach((icon) => document.head.append(icon))
})

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
    :class="['app-layout', { accented: workspace.current?.accent_color, phone }]"
    :style="workspace.current?.accent_color ? { '--workspace-accent': workspace.current.accent_color } : undefined"
  >
    <MobileNav v-if="phone" />
    <NavBar v-else />
    <main class="app-content">
      <RouterView />
    </main>
    <WorkspacesDialog v-model:visible="workspacesDialogVisible" />
    <Toast :breakpoints="{ '768px': { width: 'calc(100vw - 1.5rem)', right: '0.75rem', left: '0.75rem' } }" />
  </div>
</template>

<style scoped>
.app-layout {
  display: flex;
  min-height: 100dvh;
}

.app-layout.accented:not(.phone) {
  border-left: 4px solid var(--workspace-accent);
}

/* The bars of the phone : one above the content, one fixed below it */
.app-layout.phone {
  flex-direction: column;
}

/* A column : an empty state can grow to the bottom of the page and center itself in it */
.app-content {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  padding: var(--page-gutter);
  box-sizing: border-box;
}

/* Clear of the tab bar, whatever the height of the home indicator */
.phone .app-content {
  padding-bottom: calc(var(--page-gutter) + 4rem + env(safe-area-inset-bottom));
}
</style>
