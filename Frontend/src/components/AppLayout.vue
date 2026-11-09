<script setup lang="ts">
import { RouterView } from 'vue-router'
import NavBar from './NavBar.vue'
</script>

<template>
  <div class="app-layout">
    <NavBar />
    <main class="app-content">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.app-layout {
  display: flex;
  min-height: 100dvh;
}

.app-content {
  flex: 1;
  min-width: 0;
  padding: 1.5rem;
  box-sizing: border-box;
}
</style>
