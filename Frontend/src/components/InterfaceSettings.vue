<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import { LANGUAGE_CHOICES, languageChoice } from '../i18n'
import { THEME_MODES, themeMode } from '../utils/theme'

const { t } = useI18n()

const themeOptions = computed(() => THEME_MODES.map((value) => ({ value, label: t(`settings.interface.theme.${value}`) })))
const languageOptions = computed(() => LANGUAGE_CHOICES.map((value) => ({ value, label: t(`settings.interface.language.${value}`) })))
</script>

<template>
  <section class="panel">
    <h2>{{ t('settings.interface.title') }}</h2>
    <p class="description">{{ t('settings.interface.description') }}</p>

    <div class="fields">
      <div class="field">
        <label for="interface-language">{{ t('settings.interface.languageLabel') }}</label>
        <Select v-model="languageChoice" input-id="interface-language" :options="languageOptions" option-label="label" option-value="value" class="language" />
      </div>

      <div class="field">
        <span id="theme-label" class="label">{{ t('settings.interface.themeLabel') }}</span>
        <SelectButton v-model="themeMode" :options="themeOptions" option-label="label" option-value="value" :allow-empty="false" aria-labelledby="theme-label" />
        <small>{{ t('settings.interface.themeHint') }}</small>
      </div>
    </div>
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

.fields {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.field {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.375rem;
}

.label {
  font-weight: 500;
}

.language {
  width: min(16rem, 100%);
}

.field small {
  color: var(--p-text-muted-color);
}
</style>
