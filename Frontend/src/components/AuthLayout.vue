<script setup lang="ts">
defineProps<{ title: string; subtitle: string }>()
</script>

<template>
  <main class="auth-page">
    <section class="auth-card">
      <header class="auth-header">
        <img src="/favicon.svg" alt="" width="48" height="48" />
        <h1>{{ title }}</h1>
        <p>{{ subtitle }}</p>
      </header>

      <slot />

      <footer v-if="$slots.footer" class="auth-footer">
        <slot name="footer" />
      </footer>
    </section>
  </main>
</template>

<style scoped>
.auth-page {
  min-height: 100dvh;
  display: grid;
  place-items: center;
  padding: 1rem;
  box-sizing: border-box;
}

.auth-card {
  width: 100%;
  max-width: 24rem;
  padding: 2rem;
  box-sizing: border-box;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.auth-header {
  text-align: center;
  margin-bottom: 1.5rem;
}

.auth-header img {
  border-radius: 0.75rem;
}

.auth-header h1 {
  margin: 0.75rem 0 0.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.auth-header p {
  margin: 0;
  color: var(--p-text-muted-color);
}

.auth-footer {
  margin-top: 1.5rem;
  text-align: center;
  color: var(--p-text-muted-color);
}
</style>
