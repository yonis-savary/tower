<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Password from 'primevue/password'
import AuthLayout from '../components/AuthLayout.vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)

function errorMessage(status: number | null): string {
  if (status === 401) return t('login.invalidCredentials')
  if (status === 422) return t('login.invalidEmail')
  return t('auth.genericError')
}

async function submit() {
  submitting.value = true
  error.value = null

  const result = await auth.signIn({ email: email.value.trim(), password: password.value })
  submitting.value = false

  if (!result.ok) {
    error.value = errorMessage(result.status)
    return
  }

  const redirect = route.query.redirect
  await router.push(typeof redirect === 'string' ? redirect : { name: 'home' })
}
</script>

<template>
  <AuthLayout :title="t('login.title')" :subtitle="t('login.subtitle')">
    <form class="auth-form" @submit.prevent="submit">
      <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

      <div class="auth-field">
        <label for="email">{{ t('auth.email') }}</label>
        <InputText id="email" v-model="email" type="email" autocomplete="email" required autofocus fluid />
      </div>

      <div class="auth-field">
        <label for="password">{{ t('auth.password') }}</label>
        <Password
          v-model="password"
          input-id="password"
          :input-props="{ autocomplete: 'current-password', required: true }"
          :feedback="false"
          toggle-mask
          fluid
        />
      </div>

      <Button type="submit" :label="t('login.submit')" :loading="submitting" fluid />
    </form>

    <template #footer>
      {{ t('login.noAccount') }}
      <RouterLink :to="{ name: 'register' }">{{ t('login.registerLink') }}</RouterLink>
    </template>
  </AuthLayout>
</template>
