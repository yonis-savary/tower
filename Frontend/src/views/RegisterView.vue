<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Password from 'primevue/password'
import AuthLayout from '../components/AuthLayout.vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const { t } = useI18n()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)

function errorMessage(status: number | null): string {
  if (status === 403) return t('register.closed')
  if (status === 409) return t('register.emailTaken')
  if (status === 422) return t('register.invalid')
  return t('auth.genericError')
}

async function submit() {
  submitting.value = true
  error.value = null

  const result = await auth.signUp({ email: email.value.trim(), password: password.value })
  submitting.value = false

  if (!result.ok) {
    error.value = errorMessage(result.status)
    return
  }

  await router.push({ name: 'home' })
}
</script>

<template>
  <AuthLayout :title="t('register.title')" :subtitle="t('register.subtitle')">
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
          :input-props="{ autocomplete: 'new-password', required: true, minlength: 8 }"
          :feedback="false"
          toggle-mask
          fluid
        />
        <small>{{ t('register.passwordHint') }}</small>
      </div>

      <Button type="submit" :label="t('register.submit')" :loading="submitting" fluid />
    </form>

    <template #footer>
      {{ t('register.hasAccount') }}
      <RouterLink :to="{ name: 'login' }">{{ t('register.loginLink') }}</RouterLink>
    </template>
  </AuthLayout>
</template>
