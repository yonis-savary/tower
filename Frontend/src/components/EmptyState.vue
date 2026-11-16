<script setup lang="ts">
import AppIcon from './AppIcon.vue'

withDefaults(defineProps<{ icon: string; title: string; hint: string; compact?: boolean }>(), { compact: false })
</script>

<template>
  <div :class="['empty-state', { compact }]">
    <span class="empty-state-icon"><AppIcon :icon="icon" /></span>
    <p class="empty-state-title">{{ title }}</p>
    <p class="empty-state-hint">{{ hint }}</p>
    <div v-if="$slots.default" class="empty-state-actions">
      <slot />
    </div>
  </div>
</template>

<style scoped>
/* Grows in a column container, so that it sits in the middle of what is left of it */
.empty-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.375rem;
  padding: 2.5rem 1rem;
  text-align: center;
}

.empty-state.compact {
  padding: 1.5rem 1rem;
}

.empty-state-icon {
  display: grid;
  place-items: center;
  width: 3.5rem;
  height: 3.5rem;
  margin-bottom: 0.5rem;
  border-radius: 50%;
  background: light-dark(var(--p-primary-50), color-mix(in srgb, var(--p-primary-500) 16%, transparent));
  color: var(--p-primary-color);
  font-size: 1.5rem;
}

.compact .empty-state-icon {
  width: 2.75rem;
  height: 2.75rem;
  font-size: 1.125rem;
}

.empty-state-icon :deep(.app-icon) {
  font-size: inherit;
}

.empty-state-title {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.empty-state-hint {
  max-width: 28rem;
  margin: 0;
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
  line-height: 1.5;
}

.empty-state-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.5rem;
  margin-top: 0.75rem;
}
</style>
