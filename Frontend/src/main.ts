import { createApp } from 'vue'
import PrimeVue from 'primevue/config'
import { definePreset, palette } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'
import App from './App.vue'

const TowerPreset = definePreset(Aura, {
  semantic: {
    primary: palette('#2190FF'),
  },
})

createApp(App)
  .use(PrimeVue, { theme: { preset: TowerPreset } })
  .mount('#app')
