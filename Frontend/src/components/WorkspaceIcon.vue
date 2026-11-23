<script setup lang="ts">
import TowerLogo from './TowerLogo.vue'

withDefaults(defineProps<{ icon: string | null | undefined; color: string | null | undefined; size?: number }>(), { size: 28 })
</script>

<template>
  <img v-if="icon" :src="icon" alt="" class="workspace-icon" :width="size" :height="size" />
  <TowerLogo v-else :color="color" :size="size" />
</template>

<style scoped>
.workspace-icon {
  flex: none;
  /* Same rounding as the logo it replaces */
  border-radius: 22%;
  object-fit: cover;
}
</style>
