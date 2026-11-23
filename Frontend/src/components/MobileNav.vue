<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import Badge from 'primevue/badge'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import AppIcon from './AppIcon.vue'
import WorkspaceIcon from './WorkspaceIcon.vue'
import WorkspaceMenu from './WorkspaceMenu.vue'
import { useAuthStore } from '../stores/auth'
import { useDeviceSocketStore } from '../stores/deviceSocket'
import { useWorkspaceStore } from '../stores/workspace'
import { useNavigation, type NavItem } from '../utils/navigation'

// The tab bar holds Home and the first sections, the "More" sheet everything else
const TAB_SECTIONS = 3

const auth = useAuthStore()
const workspace = useWorkspaceStore()
const deviceSocket = useDeviceSocketStore()
const route = useRoute()
const { t } = useI18n()
const { workspaceLabel, home, sectionItems, devicesItem, secondaryItems, socketIndicator, logOut } = useNavigation()

const workspaceMenu = useTemplateRef<InstanceType<typeof WorkspaceMenu>>('workspaceMenu')
const sheetVisible = ref(false)

const tabItems = computed<NavItem[]>(() => [home.value, ...sectionItems.value.slice(0, TAB_SECTIONS)])
const sheetSections = computed<NavItem[]>(() => sectionItems.value.slice(TAB_SECTIONS))
const sheetItems = computed<NavItem[]>(() => [...sheetSections.value, devicesItem.value, ...secondaryItems.value])
// "More" stands for the screen open when it is one of its own
const moreActive = computed(() => sheetItems.value.some(({ name }) => name === route.name))
// A counter hidden in the sheet still shows on its button
const moreCount = computed(() => sheetSections.value.reduce((total, { count }) => total + (count ?? 0), 0))

// By name : router-link-active marks Home, the empty path, active on every screen
const isActive = (item: NavItem) => route.name === item.name

watch(() => route.fullPath, () => (sheetVisible.value = false))
</script>

<template>
  <header class="mobile-topbar">
    <button type="button" class="topbar-workspace" aria-haspopup="true" :title="workspaceLabel" @click="workspaceMenu?.toggle($event)">
      <WorkspaceIcon :icon="workspace.current?.icon" :color="workspace.current?.accent_color" :size="30" />
      <span class="topbar-workspace-name">{{ workspaceLabel }}</span>
      <i class="pi pi-angle-down" aria-hidden="true" />
    </button>
    <WorkspaceMenu ref="workspaceMenu" />

    <RouterLink :to="devicesItem.to" class="topbar-devices" :title="`${t('nav.devices')} · ${socketIndicator.label}`" :aria-label="`${t('nav.devices')} · ${socketIndicator.label}`">
      <i class="pi pi-desktop" aria-hidden="true" />
      <span :class="['topbar-socket', `topbar-socket-${deviceSocket.status}`]" :data-socket-status="deviceSocket.status" aria-hidden="true" />
      <span class="topbar-devices-count">{{ deviceSocket.activeDevices }}</span>
    </RouterLink>
  </header>

  <nav class="mobile-tabbar" :aria-label="t('nav.label')">
    <RouterLink
      v-for="item in tabItems"
      :key="item.name"
      :to="item.to"
      :class="['tab', { 'tab-active': isActive(item) }]"
      active-class=""
      exact-active-class=""
      :aria-label="item.label"
      :aria-current="isActive(item) ? 'page' : undefined"
    >
      <span class="tab-icon">
        <AppIcon :icon="item.icon" />
        <Badge v-if="item.count" :value="item.count" size="small" class="tab-badge" />
      </span>
      <span class="tab-label">{{ item.short ?? item.label }}</span>
    </RouterLink>
    <button type="button" :class="['tab', { 'tab-active': moreActive }]" :aria-expanded="sheetVisible" aria-haspopup="dialog" @click="sheetVisible = true">
      <span class="tab-icon">
        <i class="pi pi-bars" aria-hidden="true" />
        <Badge v-if="moreCount" :value="moreCount" size="small" class="tab-badge" />
      </span>
      <span class="tab-label">{{ t('nav.more') }}</span>
    </button>
  </nav>

  <Drawer v-model:visible="sheetVisible" position="bottom" :header="t('nav.more')" class="mobile-sheet" block-scroll>
    <ul class="sheet-grid">
      <li v-for="item in sheetItems" :key="item.name">
        <RouterLink :to="item.to" :class="['sheet-tile', { 'sheet-tile-active': isActive(item) }]" active-class="" exact-active-class="" :aria-current="isActive(item) ? 'page' : undefined">
          <span class="tab-icon">
            <AppIcon :icon="item.icon" />
            <Badge v-if="item.count" :value="item.count" size="small" class="tab-badge" />
          </span>
          <span class="sheet-tile-label">{{ item.label }}</span>
        </RouterLink>
      </li>
    </ul>

    <div class="sheet-user">
      <i class="pi pi-user" aria-hidden="true" />
      <span class="sheet-email">{{ auth.user?.email }}</span>
      <Button :label="t('nav.logout')" icon="pi pi-sign-out" severity="secondary" text size="small" @click="logOut" />
    </div>
  </Drawer>
</template>

<style scoped>
.mobile-topbar {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: calc(0.5rem + env(safe-area-inset-top)) 0.75rem 0.5rem;
  background: var(--p-content-background);
  border-bottom: 1px solid var(--p-content-border-color);
  /* The accent of the workspace underlines the bar */
  box-shadow: inset 0 -3px 0 var(--workspace-accent, transparent);
}

.topbar-workspace {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 0.625rem;
  padding: 0.25rem 0.5rem 0.25rem 0.25rem;
  color: var(--p-text-color);
  background: transparent;
  border: 0;
  border-radius: var(--p-content-border-radius);
  font: inherit;
  font-weight: 600;
  text-align: left;
  cursor: pointer;
}

.topbar-workspace:active {
  background: var(--p-content-hover-background);
}


.topbar-workspace-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.topbar-workspace .pi {
  flex: none;
  color: var(--p-text-muted-color);
}

.topbar-devices {
  flex: none;
  display: flex;
  align-items: center;
  gap: 0.375rem;
  min-height: 2.5rem;
  padding: 0 0.75rem;
  border: 1px solid light-dark(var(--p-blue-200), var(--p-blue-800));
  border-radius: 999px;
  background: light-dark(var(--p-blue-50), color-mix(in srgb, var(--p-blue-500) 16%, transparent));
  color: light-dark(var(--p-blue-700), var(--p-blue-200));
  font-size: 0.875rem;
  font-weight: 600;
  text-decoration: none;
}

.topbar-devices.router-link-active {
  background: light-dark(var(--p-blue-100), color-mix(in srgb, var(--p-blue-500) 24%, transparent));
}

.topbar-socket {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: var(--p-text-muted-color);
}

.topbar-socket-connected {
  background: light-dark(var(--p-green-600), var(--p-green-400));
}

.topbar-socket-disconnected {
  background: light-dark(var(--p-red-600), var(--p-red-400));
}

.topbar-socket-connecting {
  animation: pulse 1s ease-in-out infinite alternate;
}

@keyframes pulse {
  to {
    opacity: 0.3;
  }
}

.mobile-tabbar {
  position: fixed;
  inset: auto 0 0;
  z-index: 10;
  display: flex;
  padding: 0.25rem 0.25rem env(safe-area-inset-bottom);
  background: color-mix(in srgb, var(--p-content-background) 92%, transparent);
  backdrop-filter: blur(12px);
  border-top: 1px solid var(--p-content-border-color);
}

.tab {
  flex: 1 1 0;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.125rem;
  min-height: 3.25rem;
  padding: 0.375rem 0.125rem;
  color: var(--p-text-muted-color);
  background: transparent;
  border: 0;
  border-radius: var(--p-content-border-radius);
  font: inherit;
  text-decoration: none;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}

.tab-active {
  color: var(--p-primary-color);
}

.tab:focus-visible,
.sheet-tile:focus-visible,
.topbar-workspace:focus-visible,
.topbar-devices:focus-visible {
  outline: 2px solid var(--p-primary-color);
  outline-offset: -2px;
}

/* The icon of the active tab sits on a pill, as on the native bars */
.tab-icon {
  position: relative;
  display: grid;
  place-items: center;
  width: 3.5rem;
  height: 1.75rem;
  border-radius: 999px;
  font-size: 1.125rem;
  transition: background 0.2s ease;
}

.tab-active .tab-icon {
  background: var(--p-highlight-background);
}

.tab-badge {
  position: absolute;
  top: -0.25rem;
  right: 0.25rem;
}

.tab-label {
  max-width: 100%;
  overflow: hidden;
  font-size: 0.6875rem;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sheet-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.sheet-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.375rem;
  padding: 0.875rem 0.25rem;
  color: var(--p-text-color);
  background: var(--p-content-hover-background);
  border-radius: var(--p-content-border-radius);
  text-decoration: none;
  -webkit-tap-highlight-color: transparent;
}

.sheet-tile-active {
  background: var(--p-highlight-background);
  color: var(--p-highlight-color);
}

.sheet-tile-label {
  max-width: 100%;
  font-size: 0.8125rem;
  text-align: center;
  overflow-wrap: anywhere;
}

.sheet-user {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 1rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--p-content-border-color);
  color: var(--p-text-muted-color);
}

.sheet-email {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  font-size: 0.875rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>

<!-- The drawer is teleported to the body : out of reach of the scoped styles -->
<style>
/* .p-drawer-bottom : as precise as the 10rem height of PrimeVue, which it replaces */
.p-drawer-bottom .p-drawer.mobile-sheet {
  height: auto;
  max-height: 85dvh;
  padding-bottom: env(safe-area-inset-bottom);
  border-radius: 1rem 1rem 0 0;
}

.p-drawer.mobile-sheet .p-drawer-header {
  padding: 1rem 1rem 0.5rem;
}

.p-drawer.mobile-sheet .p-drawer-title {
  font-size: 1rem;
}

.p-drawer.mobile-sheet .p-drawer-content {
  padding: 0.5rem 1rem 1rem;
}
</style>
