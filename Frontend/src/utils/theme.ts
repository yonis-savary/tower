import { ref, watch } from 'vue'

export type ThemeMode = 'auto' | 'light' | 'dark'
export const THEME_MODES: ThemeMode[] = ['auto', 'light', 'dark']

// PrimeVue switches its dark tokens on this class, see main.ts
export const DARK_CLASS = 'app-dark'

const STORAGE_KEY = 'tower.theme'
const systemDark = window.matchMedia('(prefers-color-scheme: dark)')

function storedMode(): ThemeMode {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return THEME_MODES.find((mode) => mode === value) ?? 'auto'
  } catch {
    return 'auto'
  }
}

export const themeMode = ref<ThemeMode>(storedMode())

// The page follows the class for PrimeVue, and color-scheme for the light-dark() of the styles
function apply(): void {
  const dark = themeMode.value === 'dark' || (themeMode.value === 'auto' && systemDark.matches)
  document.documentElement.classList.toggle(DARK_CLASS, dark)
  document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
}

watch(themeMode, (mode) => {
  try {
    localStorage.setItem(STORAGE_KEY, mode)
  } catch {
    // Private browsing or blocked storage : the choice only lasts for this page
  }
  apply()
})

systemDark.addEventListener('change', apply)
apply()
