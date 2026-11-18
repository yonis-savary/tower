import type { Component } from 'vue'
import { PLUG_ICON } from '../../components/AppIcon.vue'
import EnDocsAgents from './en/DocsAgents.vue'
import EnDocsConnector from './en/DocsConnector.vue'
import EnDocsEvents from './en/DocsEvents.vue'
import EnDocsHome from './en/DocsHome.vue'
import EnDocsIntegrations from './en/DocsIntegrations.vue'
import EnDocsLinuxServer from './en/DocsLinuxServer.vue'
import EnDocsLinuxService from './en/DocsLinuxService.vue'
import EnDocsNotifications from './en/DocsNotifications.vue'
import EnDocsWebhooks from './en/DocsWebhooks.vue'
import FrDocsAgents from './fr/DocsAgents.vue'
import FrDocsConnector from './fr/DocsConnector.vue'
import FrDocsEvents from './fr/DocsEvents.vue'
import FrDocsHome from './fr/DocsHome.vue'
import FrDocsIntegrations from './fr/DocsIntegrations.vue'
import FrDocsLinuxServer from './fr/DocsLinuxServer.vue'
import FrDocsLinuxService from './fr/DocsLinuxService.vue'
import FrDocsNotifications from './fr/DocsNotifications.vue'
import FrDocsWebhooks from './fr/DocsWebhooks.vue'

export type DocPage = 'home' | 'connector' | 'events' | 'webhooks' | 'notifications' | 'integrations' | 'linux-service' | 'linux-server' | 'agents'

// One version per language : a page missing from a language falls back on French
export const DOC_PAGES: { value: DocPage; icon: string; title: string; versions: { fr: Component; en?: Component } }[] = [
  { value: 'home', icon: 'pi pi-book', title: 'docs.pages.home', versions: { fr: FrDocsHome, en: EnDocsHome } },
  { value: 'connector', icon: PLUG_ICON, title: 'docs.pages.connector', versions: { fr: FrDocsConnector, en: EnDocsConnector } },
  { value: 'events', icon: 'pi pi-bolt', title: 'docs.pages.events', versions: { fr: FrDocsEvents, en: EnDocsEvents } },
  { value: 'webhooks', icon: 'pi pi-globe', title: 'docs.pages.webhooks', versions: { fr: FrDocsWebhooks, en: EnDocsWebhooks } },
  { value: 'notifications', icon: 'pi pi-bell', title: 'docs.pages.notifications', versions: { fr: FrDocsNotifications, en: EnDocsNotifications } },
  { value: 'agents', icon: 'pi pi-sparkles', title: 'docs.pages.agents', versions: { fr: FrDocsAgents, en: EnDocsAgents } },
  { value: 'integrations', icon: 'pi pi-link', title: 'docs.pages.integrations', versions: { fr: FrDocsIntegrations, en: EnDocsIntegrations } },
  { value: 'linux-service', icon: 'pi pi-desktop', title: 'docs.pages.linuxService', versions: { fr: FrDocsLinuxService, en: EnDocsLinuxService } },
  { value: 'linux-server', icon: 'pi pi-server', title: 'docs.pages.linuxServer', versions: { fr: FrDocsLinuxServer, en: EnDocsLinuxServer } },
]
