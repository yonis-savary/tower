import type { Component } from 'vue'
import DocsEvents from './DocsEvents.vue'
import DocsHome from './DocsHome.vue'
import DocsLinuxService from './DocsLinuxService.vue'

export type DocPage = 'home' | 'events' | 'linux-service'

export const DOC_PAGES: { value: DocPage; icon: string; title: string; component: Component }[] = [
  { value: 'home', icon: 'pi pi-book', title: 'docs.pages.home', component: DocsHome },
  { value: 'events', icon: 'pi pi-bolt', title: 'docs.pages.events', component: DocsEvents },
  { value: 'linux-service', icon: 'pi pi-server', title: 'docs.pages.linuxService', component: DocsLinuxService },
]
