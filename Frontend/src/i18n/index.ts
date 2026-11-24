import { ref, watch } from 'vue'
import { createI18n } from 'vue-i18n'
import en from './en'
import fr from './fr'

const messages = { fr, en }
type Locale = keyof typeof messages

export type LanguageChoice = 'auto' | Locale
export const LANGUAGE_CHOICES: LanguageChoice[] = ['auto', 'fr', 'en']

const STORAGE_KEY = 'tower.language'

function storedChoice(): LanguageChoice {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return LANGUAGE_CHOICES.find((choice) => choice === value) ?? 'auto'
  } catch {
    return 'auto'
  }
}

function resolve(choice: LanguageChoice): Locale {
  if (choice !== 'auto') return choice

  const browserLanguage = navigator.language.split('-')[0]
  return browserLanguage in messages ? (browserLanguage as Locale) : 'fr'
}

export const languageChoice = ref<LanguageChoice>(storedChoice())

const i18n = createI18n<[typeof fr], Locale, false>({
  legacy: false,
  locale: resolve(languageChoice.value),
  fallbackLocale: 'fr',
  messages,
  datetimeFormats: {
    fr: { short: { dateStyle: 'short', timeStyle: 'short' }, day: { weekday: 'short', day: 'numeric', month: 'short' }, time: { timeStyle: 'short' } },
    en: { short: { dateStyle: 'short', timeStyle: 'short' }, day: { weekday: 'short', day: 'numeric', month: 'short' }, time: { timeStyle: 'short' } },
  },
})

document.documentElement.lang = i18n.global.locale.value

watch(languageChoice, (choice) => {
  try {
    localStorage.setItem(STORAGE_KEY, choice)
  } catch {
    // Private browsing or blocked storage : the choice only lasts for this page
  }
  i18n.global.locale.value = resolve(choice)
  document.documentElement.lang = i18n.global.locale.value
})

export default i18n
