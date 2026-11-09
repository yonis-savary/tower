import { createI18n } from 'vue-i18n'
import en from './en'
import fr from './fr'

const messages = { fr, en }
type Locale = keyof typeof messages

const browserLanguage = navigator.language.split('-')[0]
const locale: Locale = browserLanguage in messages ? (browserLanguage as Locale) : 'fr'

document.documentElement.lang = locale

export default createI18n<[typeof fr], Locale, false>({
  legacy: false,
  locale,
  fallbackLocale: 'fr',
  messages,
  datetimeFormats: {
    fr: { short: { dateStyle: 'short', timeStyle: 'short' } },
    en: { short: { dateStyle: 'short', timeStyle: 'short' } },
  },
})
