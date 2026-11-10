<script setup lang="ts" generic="T extends string">
import AppIcon from './AppIcon.vue'

defineProps<{ items: { value: T; icon: string; label: string }[]; label: string }>()
const selected = defineModel<T>({ required: true })
</script>

<template>
  <div class="side-tabs-layout">
    <nav class="side-tabs" :aria-label="label">
      <button
        v-for="item in items"
        :key="item.value"
        type="button"
        :class="['side-tab', { selected: item.value === selected }]"
        :aria-current="item.value === selected ? 'page' : undefined"
        @click="selected = item.value"
      >
        <AppIcon :icon="item.icon" />
        {{ item.label }}
      </button>
    </nav>

    <div class="side-tabs-content">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.side-tabs-layout {
  display: grid;
  grid-template-columns: 13rem minmax(0, 48rem);
  align-items: start;
  gap: 1.25rem;
}

@media (max-width: 48rem) {
  .side-tabs-layout {
    grid-template-columns: 1fr;
  }
}

.side-tabs {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.side-tab {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  padding: 0.5rem 0.75rem;
  color: var(--p-text-color);
  background: transparent;
  border: 0;
  border-radius: var(--p-content-border-radius);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.side-tab:hover {
  background: var(--p-content-hover-background);
}

.side-tab.selected {
  background: var(--p-highlight-background);
  color: var(--p-highlight-color);
}

.side-tab:focus-visible {
  outline: 2px solid var(--p-primary-color);
}
</style>
