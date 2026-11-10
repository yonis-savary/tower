<script lang="ts">
// PrimeIcons has no Git icon : this one is drawn, and named like a PrimeIcon so both are used alike
export const PULL_REQUEST_ICON = 'pull-request'
</script>

<script setup lang="ts">
defineProps<{ icon: string }>()
</script>

<template>
  <svg
    v-if="icon === PULL_REQUEST_ICON"
    class="app-icon app-icon-svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <circle cx="6" cy="5.5" r="2.5" />
    <circle cx="6" cy="18.5" r="2.5" />
    <circle cx="18" cy="18.5" r="2.5" />
    <path d="M6 8v8" />
    <path d="M18 16V9a3 3 0 0 0-3-3h-4" />
    <path d="M13.5 3.5 11 6l2.5 2.5" />
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
