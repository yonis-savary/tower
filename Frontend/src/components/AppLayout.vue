<script setup lang="ts">
import { RouterView } from 'vue-router'
import Toast from 'primevue/toast'
import NavBar from './NavBar.vue'
</script>

<template>
  <div class="app-layout">
    <NavBar />
    <main class="app-content">
      <RouterView />
    </main>
    <Toast />
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
