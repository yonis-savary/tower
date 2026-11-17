<script setup lang="ts">
import { useId } from 'vue'

withDefaults(defineProps<{ color?: string; height?: number }>(), { color: '#2190FF', height: 32 })

// The mask is referenced by id : one per instance, or two marks on a page would share the first one
const maskId = `tower-mark-${useId()}`
</script>

<!-- Same drawing as Public/logo-mark.svg : keep them in sync -->
<template>
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="128 108 256 306" :width="(height * 256) / 306" :height="height" aria-hidden="true">
    <mask :id="maskId">
      <rect x="128" y="108" width="256" height="306" fill="#FFFFFF" />
      <rect x="246.933" y="256" width="18.133" height="44" rx="9.067" ry="8" fill="#000000" />
      <path d="M224.267 414 V370 a31.733 28 0 0 1 63.467 0 V414 Z" fill="#000000" />
    </mask>
    <g :fill="color" :mask="`url(#${maskId})`">
      <rect x="128" y="108" width="52" height="70" rx="12" />
      <rect x="230" y="108" width="52" height="70" rx="12" />
      <rect x="332" y="108" width="52" height="70" rx="12" />
      <rect x="128" y="150" width="256" height="63.752" rx="16" />
      <rect x="188" y="186" width="136" height="228" rx="18.133" />
    </g>
  </svg>
</template>
