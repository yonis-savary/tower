<script lang="ts">
// PrimeIcons has no Git nor plug icon : these are drawn, and named like a PrimeIcon so both are used alike
export const PULL_REQUEST_ICON = 'pull-request'
export const PLUG_ICON = 'plug'
const DRAWN_ICONS = [PULL_REQUEST_ICON, PLUG_ICON]
</script>

<script setup lang="ts">
defineProps<{ icon: string }>()
</script>

<template>
  <svg
    v-if="DRAWN_ICONS.includes(icon)"
    class="app-icon app-icon-svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <template v-if="icon === PULL_REQUEST_ICON">
      <circle cx="6" cy="5.5" r="2.5" />
      <circle cx="6" cy="18.5" r="2.5" />
      <circle cx="18" cy="18.5" r="2.5" />
      <path d="M6 8v8" />
      <path d="M18 16V9a3 3 0 0 0-3-3h-4" />
      <path d="M13.5 3.5 11 6l2.5 2.5" />
    </template>
    <template v-else-if="icon === PLUG_ICON">
      <path d="M9 2v5" />
      <path d="M15 2v5" />
      <path d="M6 7h12v4a6 6 0 0 1-12 0z" />
      <path d="M12 17v2a3 3 0 0 0 3 3h3" />
    </template>
  </svg>
  <i v-else :class="['app-icon', icon]" aria-hidden="true" />
</template>

<style scoped>
.app-icon-svg {
  width: 1em;
  height: 1em;
  vertical-align: -0.125em;
}
</style>
