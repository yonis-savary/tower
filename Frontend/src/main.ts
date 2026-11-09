import { createApp } from 'vue'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { definePreset, palette } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'
import { createPinia } from 'pinia'
import App from './App.vue'
import i18n from './i18n'
import router from './router'
import 'primeicons/primeicons.css'
import './style.css'

const TowerPreset = definePreset(Aura, {
  semantic: {
    primary: palette('#2190FF'),
  },
})

createApp(App)
  .use(PrimeVue, { theme: { preset: TowerPreset } })
  .use(ToastService)
  .use(createPinia())
  .use(i18n)
  .use(router) // Last : its first navigation runs the guard, which reads the auth store
  .mount('#app')
