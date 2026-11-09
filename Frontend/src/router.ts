import { createRouter, createWebHistory } from 'vue-router'
import AppLayout from './components/AppLayout.vue'
import { useAuthStore } from './stores/auth'
import EventsView from './views/EventsView.vue'
import HistoryView from './views/HistoryView.vue'
import HomeView from './views/HomeView.vue'
import LoginView from './views/LoginView.vue'
import MergeRequestsView from './views/MergeRequestsView.vue'
import RegisterView from './views/RegisterView.vue'
import SettingsView from './views/SettingsView.vue'
import TasksView from './views/TasksView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: AppLayout,
      children: [
        { path: '', name: 'home', component: HomeView },
        { path: 'tasks', name: 'tasks', component: TasksView },
        { path: 'events', name: 'events', component: EventsView },
        { path: 'history', name: 'history', component: HistoryView },
        { path: 'merge-requests', name: 'merge-requests', component: MergeRequestsView },
        { path: 'settings', name: 'settings', component: SettingsView },
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
