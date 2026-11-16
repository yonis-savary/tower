import { createRouter, createWebHistory } from 'vue-router'
import AppLayout from './components/AppLayout.vue'
import AutomationView from './views/AutomationView.vue'
import { useAuthStore } from './stores/auth'
import DocsView from './views/DocsView.vue'
import EventsView from './views/EventsView.vue'
import HistoryView from './views/HistoryView.vue'
import HomeView from './views/HomeView.vue'
import JiraView from './views/JiraView.vue'
import LoginView from './views/LoginView.vue'
import MergeRequestsView from './views/MergeRequestsView.vue'
import RegisterView from './views/RegisterView.vue'
import SettingsView from './views/SettingsView.vue'
import TasksView from './views/TasksView.vue'
import WebhooksView from './views/WebhooksView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: AppLayout,
      children: [
        { path: '', name: 'home', component: HomeView, meta: { title: 'nav.home' } },
        { path: 'tasks', name: 'tasks', component: TasksView, meta: { title: 'nav.tasks', section: 'tasks' } },
        { path: 'events', name: 'events', component: EventsView, meta: { title: 'nav.events', section: 'events' } },
        { path: 'automation', name: 'automation', component: AutomationView, meta: { title: 'nav.automation', section: 'automation' } },
        { path: 'webhooks', name: 'webhooks', component: WebhooksView, meta: { title: 'nav.webhooks', section: 'webhooks' } },
        { path: 'history', name: 'history', component: HistoryView, meta: { title: 'nav.history' } },
        { path: 'jira', name: 'jira', component: JiraView, meta: { title: 'nav.jira', section: 'jira' } },
        { path: 'merge-requests', name: 'merge-requests', component: MergeRequestsView, meta: { title: 'nav.mergeRequests', section: 'merge_requests' } },
        { path: 'docs', name: 'docs', component: DocsView, meta: { title: 'nav.docs' } },
        { path: 'settings', name: 'settings', component: SettingsView, meta: { title: 'nav.settings' } },
      ],
    },
    { path: '/login', name: 'login', component: LoginView, meta: { guestOnly: true } },
    { path: '/register', name: 'register', component: RegisterView, meta: { guestOnly: true } },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.loadSession()

  if (to.meta.guestOnly) return auth.user ? { name: 'home' } : true
  if (auth.user) return true

  return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
})

export default router
