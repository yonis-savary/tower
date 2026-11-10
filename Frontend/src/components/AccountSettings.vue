<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Password from 'primevue/password'
import SelectButton from 'primevue/selectbutton'
import { changePassword } from '../client'
import { useAuthStore } from '../stores/auth'
import { THEME_MODES, themeMode } from '../utils/theme'

const { t } = useI18n()
const auth = useAuthStore()

const form = ref({ current: '', next: '', confirmation: '' })
const busy = ref(false)
const result = ref<{ severity: 'success' | 'error'; text: string } | null>(null)

const themeOptions = computed(() => THEME_MODES.map((value) => ({ value, label: t(`settings.account.theme.${value}`) })))
const mismatch = computed(() => form.value.confirmation !== '' && form.value.confirmation !== form.value.next)

async function submit() {
  if (mismatch.value) return

  busy.value = true
  result.value = null
  const { response } = await changePassword({ body: { current_password: form.value.current, new_password: form.value.next } })
  busy.value = false

  if (!response?.ok) {
    result.value = { severity: 'error', text: t('settings.account.passwordError') }
    return
  }

  form.value = { current: '', next: '', confirmation: '' }
  result.value = { severity: 'success', text: t('settings.account.passwordChanged') }
}
</script>

<template>
  <section class="panel">
    <h2>{{ t('settings.account.title') }}</h2>
    <p class="description">{{ auth.user?.email }}</p>

    <div class="field">
      <span class="label" id="theme-label">{{ t('settings.account.themeLabel') }}</span>
      <SelectButton v-model="themeMode" :options="themeOptions" option-label="label" option-value="value" :allow-empty="false" aria-labelledby="theme-label" />
      <small>{{ t('settings.account.themeHint') }}</small>
    </div>
  </section>

  <section class="panel">
    <h2>{{ t('settings.account.passwordTitle') }}</h2>

    <Message v-if="result" class="panel-message" :severity="result.severity" :closable="false">{{ result.text }}</Message>

    <form class="settings-form" @submit.prevent="submit">
      <input type="email" :value="auth.user?.email" autocomplete="username" hidden readonly />
      <div class="field">
        <label for="current-password">{{ t('settings.account.currentPassword') }}</label>
        <Password v-model="form.current" input-id="current-password" :feedback="false" :input-props="{ autocomplete: 'current-password' }" toggle-mask required fluid />
      </div>
      <div class="field">
        <label for="new-password">{{ t('settings.account.newPassword') }}</label>
        <Password v-model="form.next" input-id="new-password" :feedback="false" :input-props="{ autocomplete: 'new-password', minlength: 8 }" toggle-mask required fluid />
        <small>{{ t('settings.account.passwordHint') }}</small>
      </div>
      <div class="field">
        <label for="confirm-password">{{ t('settings.account.confirmPassword') }}</label>
        <Password v-model="form.confirmation" input-id="confirm-password" :feedback="false" :input-props="{ autocomplete: 'new-password' }" toggle-mask required fluid :invalid="mismatch" />
        <small v-if="mismatch" class="error">{{ t('settings.account.passwordMismatch') }}</small>
      </div>
      <div class="actions">
        <Button type="submit" :label="t('settings.account.changePassword')" :disabled="mismatch" :loading="busy" />
      </div>
    </form>
  </section>
</template>

<style scoped>
.panel {
  margin-bottom: 1.25rem;
  padding: 1.25rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel h2 {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
}

.description {
  margin: 0.25rem 0 1rem;
  color: var(--p-text-muted-color);
}

.panel h2 + .settings-form,
.panel h2 + .panel-message {
  margin-top: 1rem;
}

.panel-message {
  margin-bottom: 1rem;
}

.settings-form,
.field {
  display: flex;
  flex-direction: column;
}

.settings-form {
  gap: 1rem;
}

.field {
  align-items: flex-start;
  gap: 0.375rem;
}

.settings-form .field {
  align-items: stretch;
}

.label {
  font-weight: 500;
}

.field small {
  color: var(--p-text-muted-color);
}

.field small.error {
  color: var(--p-red-500);
}

.actions {
  display: flex;
  justify-content: flex-end;
}
</style>
