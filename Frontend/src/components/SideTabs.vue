<script setup lang="ts" generic="T extends string">
import AppIcon from './AppIcon.vue'

defineProps<{ items: { value: T; icon: string; label: string }[]; label: string }>()
const selected = defineModel<T>({ required: true })

// In the scrolling row of a phone, the chosen tab comes into view
function select(value: T, event: MouseEvent) {
  selected.value = value
  ;(event.currentTarget as HTMLElement).scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' })
}
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
        @click="select(item.value, $event)"
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
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
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

/* A phone : one row of pills scrolling sideways, from one edge of the screen to the other */
@media (max-width: 48rem) {
  .side-tabs {
    flex-direction: row;
    gap: 0.375rem;
    margin-inline: calc(-1 * var(--page-gutter));
    padding: 0.125rem var(--page-gutter);
    overflow-x: auto;
    scroll-padding-inline: var(--page-gutter);
    scrollbar-width: none;
  }

  .side-tabs::-webkit-scrollbar {
    display: none;
  }

  .side-tab {
    flex: none;
    gap: 0.5rem;
    padding: 0.5rem 0.875rem;
    border: 1px solid var(--p-content-border-color);
    border-radius: 999px;
    background: var(--p-content-background);
    font-size: 0.875rem;
    white-space: nowrap;
  }

  .side-tab.selected {
    border-color: transparent;
  }
}
</style>
