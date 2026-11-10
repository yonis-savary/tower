<script setup lang="ts">
withDefaults(defineProps<{ color?: string | null; size?: number }>(), { color: null, size: 28 })

const DEFAULT_COLOR = '#2190FF'
</script>

<!-- Same drawing as public/favicon.svg : keep both in sync -->
<template>
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" :width="size" :height="size" class="tower-logo" :style="{ '--logo-color': color ?? DEFAULT_COLOR }" aria-hidden="true">
    <rect width="512" height="512" rx="112" class="logo-accent" />
    <g fill="#FFFFFF">
      <rect x="128" y="108" width="52" height="70" rx="12" />
      <rect x="230" y="108" width="52" height="70" rx="12" />
      <rect x="332" y="108" width="52" height="70" rx="12" />
      <rect x="128" y="150" width="256" height="72" rx="16" />
      <rect x="196" y="200" width="120" height="208" rx="16" />
    </g>
    <g class="logo-accent">
      <rect x="248" y="250" width="16" height="44" rx="8" />
      <path d="M228 408 V364 a28 28 0 0 1 56 0 V408 Z" />
    </g>
  </svg>
</template>

<style scoped>
.tower-logo {
  flex: none;
}

.logo-accent {
  fill: var(--logo-color);
  transition: fill 0.4s ease;
}
</style>
