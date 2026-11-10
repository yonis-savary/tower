import type { Component } from 'vue'
import EnDocsEvents from './en/DocsEvents.vue'
import EnDocsHome from './en/DocsHome.vue'
import EnDocsLinuxService from './en/DocsLinuxService.vue'
import FrDocsEvents from './fr/DocsEvents.vue'
import FrDocsHome from './fr/DocsHome.vue'
import FrDocsLinuxService from './fr/DocsLinuxService.vue'

export type DocPage = 'home' | 'events' | 'linux-service'

// One version per language : a page missing from a language falls back on French
export const DOC_PAGES: { value: DocPage; icon: string; title: string; versions: { fr: Component; en?: Component } }[] = [
  { value: 'home', icon: 'pi pi-book', title: 'docs.pages.home', versions: { fr: FrDocsHome, en: EnDocsHome } },
  { value: 'events', icon: 'pi pi-bolt', title: 'docs.pages.events', versions: { fr: FrDocsEvents, en: EnDocsEvents } },
  { value: 'linux-service', icon: 'pi pi-server', title: 'docs.pages.linuxService', versions: { fr: FrDocsLinuxService, en: EnDocsLinuxService } },
]
