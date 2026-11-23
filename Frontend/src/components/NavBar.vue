<script setup lang="ts">
import { computed, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Badge from 'primevue/badge'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import AppIcon from './AppIcon.vue'
import TowerLogo from './TowerLogo.vue'
import { useAuthStore } from '../stores/auth'
import { useDeviceSocketStore } from '../stores/deviceSocket'
import { useWorkspaceStore } from '../stores/workspace'
import { useNavigation } from '../utils/navigation'
import { GLOBAL_SHORTCUTS } from '../utils/globalShortcuts'

// The navigation of a wide screen : the phones have MobileNav
const auth = useAuthStore()
const workspace = useWorkspaceStore()
const deviceSocket = useDeviceSocketStore()
const { t } = useI18n()
const { workspaceLabel, workspaceMenuItems, mainItems, secondaryItems, socketIndicator, logOut } = useNavigation()

const shortcuts = computed(() => GLOBAL_SHORTCUTS.filter(({ section }) => workspace.hasSection(section)))

const workspaceMenu = useTemplateRef<InstanceType<typeof Menu>>('workspaceMenu')
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
      <img v-if="workspace.current?.icon" :src="workspace.current.icon" alt="" class="navbar-workspace-icon" />
      <TowerLogo v-else :color="workspace.current?.accent_color" />
      <span class="navbar-text">{{ workspaceLabel }}</span>
      <i class="pi pi-angle-down navbar-text" aria-hidden="true" />
    </button>
    <Menu ref="workspaceMenu" :model="workspaceMenuItems" popup @show="workspace.loadWorkspaces" />

    <RouterLink
      :to="{ name: 'devices' }"
      class="navbar-item navbar-devices"
      :title="`${t('nav.devices')} · ${socketIndicator.label}`"
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
    </RouterLink>

    <ul class="navbar-list">
      <li v-for="item in mainItems" :key="item.name">
        <RouterLink :to="item.to" class="navbar-item" :title="item.label">
          <AppIcon :icon="item.icon" />
          <span class="navbar-text">{{ item.label }}</span>
          <Badge v-if="item.count" :value="item.count" severity="secondary" size="small" class="navbar-text navbar-count" />
        </RouterLink>
      </li>
    </ul>

    <div class="navbar-secondary">
      <section v-if="shortcuts.length" class="navbar-shortcuts" :aria-label="t('nav.shortcuts')">
        <h2>{{ t('nav.shortcuts') }}</h2>
        <dl>
          <div v-for="shortcut in shortcuts" :key="shortcut.key">
            <dt><kbd>{{ shortcut.key.toUpperCase() }}</kbd></dt>
            <dd>{{ t(shortcut.label) }}</dd>
          </div>
        </dl>
      </section>
      <ul class="navbar-list">
        <li v-for="item in secondaryItems" :key="item.name">
          <RouterLink :to="item.to" class="navbar-item" :title="item.label">
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

.navbar-workspace-icon {
  flex: none;
  width: 28px;
  height: 28px;
  /* Same rounding as the logo it replaces */
  border-radius: 22%;
  object-fit: cover;
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

.navbar-devices:hover,
.navbar-devices.router-link-active {
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

.navbar-shortcuts {
  padding: 0 0.75rem 0.375rem;
  color: var(--p-text-muted-color);
  font-size: 0.75rem;
}

.navbar-shortcuts h2 {
  margin: 0 0 0.25rem;
  font-size: inherit;
  font-weight: 600;
}

.navbar-shortcuts dl {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
}

.navbar-shortcuts dl > div {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.navbar-shortcuts dd {
  margin: 0;
}

.navbar-shortcuts kbd {
  display: inline-block;
  min-width: 1.25rem;
  padding: 0 0.25rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 4px;
  font-family: inherit;
  text-align: center;
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
</style>
