<script setup lang="ts">
import { Translation, useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const { t } = useI18n()

async function logOut() {
  await auth.signOut()
  await router.push({ name: 'login' })
}
</script>

<template>
  <main class="home">
    <Translation keypath="home.loggedInAs" tag="p" scope="global">
      <template #email><strong>{{ auth.user?.email }}</strong></template>
    </Translation>
    <Button :label="t('home.logout')" severity="secondary" @click="logOut" />
  </main>
</template>

<style scoped>
.home {
  min-height: 100dvh;
  display: grid;
  place-content: center;
  justify-items: center;
  gap: 1rem;
  padding: 1rem;
  box-sizing: border-box;
}
</style>
