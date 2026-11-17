<script setup lang="ts">
import { computed } from 'vue'
import TowerLogoMark from './TowerLogoMark.vue'

export type ChipLed = 'idle' | 'off' | 'pending' | 'running' | 'succeeded' | 'failed'

const props = defineProps<{
  x: number
  y: number
  width: number
  height: number
  label: string
  detail: string
  led: ChipLed
  clickable?: boolean
  logo?: boolean
}>()

const PIN_GAP = 16
const PIN_WIDTH = 6
const PIN_LENGTH = 5

const logoHeight = computed(() => Math.round(Math.min(props.width, props.height) * 0.42))
const logoWidth = computed(() => (logoHeight.value * 256) / 306)

const pins = computed(() => {
  const count = Math.floor((props.width - PIN_GAP) / PIN_GAP)
  const offset = (props.width - (count - 1) * PIN_GAP) / 2
  return Array.from({ length: count }, (_, index) => props.x + offset + index * PIN_GAP - PIN_WIDTH / 2)
})

function truncate(text: string, length: number): string {
  return text.length <= length ? text : `${text.slice(0, length - 1)}…`
}
</script>

<template>
  <g class="chip" :class="{ clickable }" :role="clickable ? 'button' : undefined" :tabindex="clickable ? 0 : undefined">
    <title>{{ detail ? `${label} · ${detail}` : label }}</title>
    <rect v-for="pinX in pins" :key="`top-${pinX}`" class="pin" :x="pinX" :y="y - PIN_LENGTH" :width="PIN_WIDTH" :height="PIN_LENGTH" />
    <rect v-for="pinX in pins" :key="`bottom-${pinX}`" class="pin" :x="pinX" :y="y + height" :width="PIN_WIDTH" :height="PIN_LENGTH" />
    <rect class="body" :x="x" :y="y" :width="width" :height="height" rx="4" />
    <circle class="notch" :cx="x + 10" :cy="y + 10" r="3" />
    <circle class="led" :class="`led-${led}`" :cx="x + width - 12" :cy="y + 12" r="4" />
    <template v-if="logo">
      <TowerLogoMark
        class="logo"
        color="var(--p-surface-500)"
        :height="logoHeight"
        :x="x + (width - logoWidth) / 2"
        :y="y + height / 2 - logoHeight / 2 - 12"
      />
      <text class="detail centered" :x="x + width / 2" :y="y + height / 2 + logoHeight / 2 + 12">{{ truncate(detail, 26) }}</text>
    </template>
    <template v-else>
      <text class="label" :x="x + 14" :y="y + height / 2 - 1">{{ truncate(label, 20) }}</text>
      <text class="detail" :x="x + 14" :y="y + height / 2 + 14">{{ truncate(detail, 30) }}</text>
    </template>
  </g>
</template>

<style scoped>
.chip.clickable {
  cursor: pointer;
}

.chip.clickable:hover .body,
.chip.clickable:focus-visible .body {
  stroke: var(--p-primary-color);
}

.chip:focus-visible {
  outline: none;
}

.pin {
  fill: light-dark(var(--p-surface-400), var(--p-surface-500));
}

.body {
  fill: light-dark(var(--p-surface-800), var(--p-surface-900));
  stroke: light-dark(var(--p-surface-700), var(--p-surface-700));
  stroke-width: 1;
}

.notch {
  fill: light-dark(var(--p-surface-700), var(--p-surface-800));
}

.label {
  fill: var(--p-surface-0);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.04em;
}

.detail {
  fill: var(--p-surface-400);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px;
}

.detail.centered {
  font-size: 13px;
  text-anchor: middle;
}

.logo {
  opacity: 0.35;
}

.led {
  transition: fill 0.3s;
}

.led-idle {
  fill: var(--p-green-500);
}

.led-off {
  fill: var(--p-surface-600);
}

.led-pending {
  fill: var(--p-amber-400);
}

.led-running {
  fill: var(--p-sky-400);
  animation: blink 0.6s ease-in-out infinite alternate;
}

.led-succeeded {
  fill: var(--p-green-400);
  filter: drop-shadow(0 0 4px var(--p-green-400));
}

.led-failed {
  fill: var(--p-red-500);
  filter: drop-shadow(0 0 4px var(--p-red-500));
}

@keyframes blink {
  to {
    opacity: 0.3;
  }
}
</style>
