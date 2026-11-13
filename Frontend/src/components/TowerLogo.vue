<script setup lang="ts">
withDefaults(defineProps<{ color?: string | null; size?: number }>(), { color: null, size: 28 })

const DEFAULT_COLOR = '#2190FF'
</script>

<!-- Same drawing as public/favicon.svg (and Public/logo.svg) : keep them in sync, then regenerate the PNG/ICO icons of public/ -->
<template>
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" :width="size" :height="size" class="tower-logo" :style="{ '--logo-color': color ?? DEFAULT_COLOR }" aria-hidden="true">
    <rect width="512" height="512" rx="112" class="logo-accent" />
    <g fill="#FFFFFF">
      <rect x="128" y="108" width="52" height="70" rx="12" />
      <rect x="230" y="108" width="52" height="70" rx="12" />
      <rect x="332" y="108" width="52" height="70" rx="12" />
      <rect x="128" y="150" width="256" height="63.752" rx="16" />
      <rect x="188" y="186" width="136" height="228" rx="18.133" />
    </g>
    <g class="logo-accent">
      <rect x="246.933" y="256" width="18.133" height="44" rx="9.067" ry="8" />
      <path d="M224.267 414 V370 a31.733 28 0 0 1 63.467 0 V414 Z" />
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
