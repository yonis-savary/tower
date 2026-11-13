<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter, type RouteLocationRaw } from 'vue-router'
import Badge from 'primevue/badge'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'
import DevicesDialog from './DevicesDialog.vue'
import AppIcon, { PULL_REQUEST_ICON } from './AppIcon.vue'
import TowerLogo from './TowerLogo.vue'
import WorkspacesDialog from './WorkspacesDialog.vue'
import { useAuthStore } from '../stores/auth'
import { useDeviceSocketStore } from '../stores/deviceSocket'
import { useJiraStore } from '../stores/jira'
import { usePullRequestsStore } from '../stores/pullRequests'
import { useTasksStore } from '../stores/tasks'
import { useWorkspaceStore, type WorkspaceSection } from '../stores/workspace'

// An item without `to` has no screen yet
type NavItem = { label: string; icon: string; to?: RouteLocationRaw; count?: number; section?: WorkspaceSection }

const auth = useAuthStore()
const workspace = useWorkspaceStore()
const deviceSocket = useDeviceSocketStore()
const tasks = useTasksStore()
const jira = useJiraStore()
const pullRequests = usePullRequestsStore()
const router = useRouter()
const { t } = useI18n()

const workspaceLabel = computed(() => workspace.current?.name ?? t('nav.workspace'))

const workspaceMenu = useTemplateRef<InstanceType<typeof Menu>>('workspaceMenu')
const workspaceMenuItems = computed<MenuItem[]>(() => [
  ...(workspace.workspaces === null
    ? [{ label: t('nav.loadingWorkspaces'), disabled: true }]
    : workspace.workspaces.map(({ id, name }) => ({
        label: name,
        // pi-fw alone keeps the labels aligned with the checked one
        icon: id === workspace.current?.id ? 'pi pi-fw pi-check' : 'pi pi-fw',
        command: () => workspace.select(id),
      }))),
  { separator: true },
  { label: t('nav.manageWorkspaces'), icon: 'pi pi-fw pi-cog', command: () => (workspacesDialogVisible.value = true) },
])

onMounted(workspace.loadCurrent)

// What is left to do in the active sprints
const remainingTasks = computed(
  () => tasks.tasks.filter(({ sprint, status }) => sprint !== null && tasks.activeSprints.some(({ id }) => id === sprint) && status !== 'done' && status !== 'cancelled').length,
)
const openIssues = computed(() => jira.mine.filter((issue) => !jira.isDone(issue)).length)

// Loaded with the page rather than with each screen, for the counters of the menu
watch(
  () => [workspace.current?.id, workspace.current?.sections] as const,
  ([id]) => {
    if (!id) return
    if (workspace.hasSection('tasks')) void tasks.load()
    if (workspace.hasSection('jira')) void jira.load()
    if (workspace.hasSection('merge_requests')) void pullRequests.load()
  },
)

const mainItems = computed<NavItem[]>(() =>
  [
    { label: t('nav.home'), icon: 'pi pi-home', to: { name: 'home' } },
    { label: t('nav.tasks'), icon: 'pi pi-check-square', to: { name: 'tasks' }, count: remainingTasks.value, section: 'tasks' as const },
    { label: t('nav.jira'), icon: 'pi pi-objects-column', to: { name: 'jira' }, count: openIssues.value, section: 'jira' as const },
    { label: t('nav.mergeRequests'), icon: PULL_REQUEST_ICON, to: { name: 'merge-requests' }, count: pullRequests.open.length, section: 'merge_requests' as const },
    { label: t('nav.events'), icon: 'pi pi-bolt', to: { name: 'events' }, section: 'events' as const },
    { label: t('nav.webhooks'), icon: 'pi pi-globe', to: { name: 'webhooks' }, section: 'webhooks' as const },
  ].filter(({ section }) => !section || workspace.hasSection(section)),
)

const secondaryItems = computed<NavItem[]>(() => [
  { label: t('nav.history'), icon: 'pi pi-history', to: { name: 'history' } },
  { label: t('nav.docs'), icon: 'pi pi-book', to: { name: 'docs' } },
  { label: t('nav.settings'), icon: 'pi pi-cog', to: { name: 'settings' } },
])

const devicesDialogVisible = ref(false)
const workspacesDialogVisible = ref(false)
const socketIndicator = computed(
  () =>
    ({
      connecting: { icon: 'pi pi-spin pi-spinner', label: t('nav.socketConnecting') },
      connected: { icon: 'pi pi-check', label: t('nav.socketConnected') },
      disconnected: { icon: 'pi pi-times', label: t('nav.socketDisconnected') },
    })[deviceSocket.status],
)

// Follows the selected workspace, and stops when the logout clears it
watch(
  () => workspace.current?.id,
  (id) => (id ? deviceSocket.connect() : deviceSocket.disconnect()),
)
onBeforeUnmount(deviceSocket.disconnect)

async function logOut() {
  await auth.signOut()
  workspace.clear()
  await router.push({ name: 'login' })
}
</script>

<template>
  <nav class="navbar" :aria-label="t('nav.label')">
    <button
      type="button"
      class="navbar-workspace"
      aria-haspopup="true"
      :title="workspaceLabel"
      @click="workspaceMenu?.toggle($event)"
    >
      <TowerLogo :color="workspace.current?.accent_color" />
      <span class="navbar-text">{{ workspaceLabel }}</span>
      <i class="pi pi-angle-down navbar-text" aria-hidden="true" />
    </button>
    <Menu ref="workspaceMenu" :model="workspaceMenuItems" popup @show="workspace.loadWorkspaces" />
    <WorkspacesDialog v-model:visible="workspacesDialogVisible" />

    <button
      type="button"
      class="navbar-item navbar-devices"
      aria-haspopup="dialog"
      :title="`${t('nav.devices')} · ${socketIndicator.label}`"
      @click="devicesDialogVisible = true"
    >
      <i class="pi pi-desktop" aria-hidden="true" />
      <span class="navbar-text">{{ t('nav.devices') }}</span>
      <span class="navbar-devices-state">
        <i
          :class="[socketIndicator.icon, `navbar-socket-${deviceSocket.status}`]"
          role="img"
          :aria-label="socketIndicator.label"
          :data-socket-status="deviceSocket.status"
        />
        <Badge :value="deviceSocket.activeDevices" severity="secondary" size="small" />
      </span>
    </button>
    <DevicesDialog v-model:visible="devicesDialogVisible" />

    <ul class="navbar-list">
      <li v-for="item in mainItems" :key="item.label">
        <component
          :is="item.to ? RouterLink : 'button'"
          class="navbar-item"
          v-bind="item.to ? { to: item.to } : { type: 'button' }"
          :title="item.label"
        >
          <AppIcon :icon="item.icon" />
          <span class="navbar-text">{{ item.label }}</span>
          <Badge v-if="item.count" :value="item.count" severity="secondary" size="small" class="navbar-text navbar-count" />
        </component>
      </li>
    </ul>

    <div class="navbar-secondary">
      <ul class="navbar-list">
        <li v-for="item in secondaryItems" :key="item.label">
          <RouterLink :to="item.to!" class="navbar-item" :title="item.label">
            <AppIcon :icon="item.icon" />
            <span class="navbar-text">{{ item.label }}</span>
          </RouterLink>
        </li>
      </ul>
    </div>

    <div class="navbar-user">
      <span class="navbar-text navbar-email" :title="auth.user?.email">{{ auth.user?.email }}</span>
      <Button
        icon="pi pi-sign-out"
        severity="secondary"
        text
        :aria-label="t('nav.logout')"
        :title="t('nav.logout')"
        @click="logOut"
      />
    </div>
  </nav>
</template>

<style scoped>
.navbar {
  position: sticky;
  top: 0;
  flex: none;
  width: 17rem;
  height: 100dvh;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.75rem;
  box-sizing: border-box;
  background: var(--p-content-background);
  border-right: 1px solid var(--p-content-border-color);
}

.navbar-workspace,
.navbar-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.625rem 0.75rem;
  box-sizing: border-box;
  border: 0;
  border-radius: var(--p-content-border-radius);
  background: transparent;
  color: var(--p-text-color);
  font: inherit;
  text-decoration: none;
  text-align: left;
  cursor: pointer;
}

.navbar-workspace {
  padding: 0.5rem;
  font-weight: 600;
}

.navbar-workspace span.navbar-text {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.navbar-workspace:hover,
.navbar-item:hover {
  background: var(--p-content-hover-background);
}

.navbar-workspace:focus-visible,
.navbar-item:focus-visible {
  outline: 2px solid var(--p-primary-color);
  outline-offset: -2px;
}

.navbar-devices {
  border: 1px solid light-dark(var(--p-blue-200), var(--p-blue-800));
  background: light-dark(var(--p-blue-50), color-mix(in srgb, var(--p-blue-500) 16%, transparent));
  color: light-dark(var(--p-blue-700), var(--p-blue-200));
}

.navbar-devices:hover {
  background: light-dark(var(--p-blue-100), color-mix(in srgb, var(--p-blue-500) 24%, transparent));
}

.navbar-count {
  margin-left: auto;
}

.navbar-devices-state {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.navbar-devices-state i {
  width: auto;
  font-size: 0.875rem;
}

.navbar-socket-connected {
  color: light-dark(var(--p-green-600), var(--p-green-400));
}

.navbar-socket-disconnected {
  color: light-dark(var(--p-red-600), var(--p-red-400));
}

.navbar-item.router-link-exact-active {
  background: var(--p-highlight-background);
  color: var(--p-highlight-color);
}

.navbar-item .app-icon {
  flex: none;
  width: 1.25rem;
  text-align: center;
}

.navbar-list {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.navbar-secondary {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  margin-top: auto;
}

.navbar-user {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.5rem 0 0 0.75rem;
  border-top: 1px solid var(--p-content-border-color);
}

.navbar-email {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

/* Always visible : on a narrow screen it only shrinks to its icons */
@media (max-width: 48rem) {
  .navbar {
    width: 3.75rem;
    padding: 0.75rem 0.5rem;
  }

  .navbar-text {
    display: none;
  }

  .navbar-workspace,
  .navbar-item,
  .navbar-user {
    justify-content: center;
    padding-inline: 0;
  }

  .navbar-devices {
    flex-direction: column;
    gap: 0.375rem;
  }

  .navbar-devices-state {
    margin-left: 0;
    gap: 0.25rem;
  }
}
</style>
