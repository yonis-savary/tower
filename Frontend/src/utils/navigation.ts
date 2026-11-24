import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import type { MenuItem } from 'primevue/menuitem'
import { PLUG_ICON, PULL_REQUEST_ICON } from '../components/AppIcon.vue'
import { useAuthStore } from '../stores/auth'
import { useDeviceSocketStore } from '../stores/deviceSocket'
import { useJiraStore } from '../stores/jira'
import { usePullRequestsStore } from '../stores/pullRequests'
import { useTasksStore } from '../stores/tasks'
import { useWorkspaceStore, type WorkspaceSection } from '../stores/workspace'

// `short` : the label of the tab bar of a phone, when the full one does not fit
export type NavItem = { name: string; label: string; short?: string; icon: string; to: RouteLocationRaw; count?: number; section?: WorkspaceSection }

// One dialog for the sidebar and the phone navigation : only one of them is mounted at a time
const workspacesDialogVisible = ref(false)

/**
 * What the sidebar (desktop) and the bars (phone) show : the same screens, counters and actions.
 */
export function useNavigation() {
  const { t } = useI18n()
  const router = useRouter()
  const auth = useAuthStore()
  const workspace = useWorkspaceStore()
  const deviceSocket = useDeviceSocketStore()
  const tasks = useTasksStore()
  const jira = useJiraStore()
  const pullRequests = usePullRequestsStore()

  const workspaceLabel = computed(() => workspace.current?.name ?? t('nav.workspace'))

  const workspaceMenuItems = computed<MenuItem[]>(() => [
    ...(workspace.workspaces === null
      ? [{ label: t('nav.loadingWorkspaces'), disabled: true }]
      : workspace.workspaces.map(({ id, name, icon, accent_color }) => ({
          label: name,
          workspace: { icon, accent_color },
          current: id === workspace.current?.id,
          command: () => workspace.select(id),
        }))),
    { separator: true },
    { label: t('nav.manageWorkspaces'), icon: 'pi pi-fw pi-cog', command: () => (workspacesDialogVisible.value = true) },
  ])

  // What is left to do in the active sprints
  const remainingTasks = computed(
    () =>
      tasks.tasks.filter(
        ({ sprint, status }) => sprint !== null && tasks.activeSprints.some(({ id }) => id === sprint) && status !== 'done' && status !== 'cancelled',
      ).length,
  )
  const openIssues = computed(() => jira.mine.filter((issue) => !jira.isDone(issue)).length)

  const home = computed<NavItem>(() => ({ name: 'home', label: t('nav.home'), icon: 'pi pi-home', to: { name: 'home' } }))

  // The sections of the workspace, in their order of importance
  const sectionItems = computed<NavItem[]>(() =>
    (
      [
        { name: 'agenda', label: t('nav.agenda'), icon: 'pi pi-calendar', to: { name: 'agenda' }, section: 'agenda' },
        { name: 'tasks', label: t('nav.tasks'), icon: 'pi pi-check-square', to: { name: 'tasks' }, count: remainingTasks.value, section: 'tasks' },
        { name: 'jira', label: t('nav.jira'), icon: 'pi pi-objects-column', to: { name: 'jira' }, count: openIssues.value, section: 'jira' },
        { name: 'merge-requests', label: t('nav.mergeRequests'), short: t('nav.mergeRequestsShort'), icon: PULL_REQUEST_ICON, to: { name: 'merge-requests' }, count: pullRequests.open.length, section: 'merge_requests' },
        { name: 'events', label: t('nav.events'), icon: 'pi pi-bolt', to: { name: 'events' }, section: 'events' },
        { name: 'automation', label: t('nav.automation'), icon: PLUG_ICON, to: { name: 'automation' }, section: 'automation' },
        { name: 'webhooks', label: t('nav.webhooks'), icon: 'pi pi-globe', to: { name: 'webhooks' }, section: 'webhooks' },
        { name: 'documentation', label: t('nav.documentation'), short: t('nav.documentationShort'), icon: 'pi pi-book', to: { name: 'documentation' }, section: 'documentation' },
      ] satisfies NavItem[]
    ).filter(({ section }) => workspace.hasSection(section)),
  )

  const mainItems = computed<NavItem[]>(() => [home.value, ...sectionItems.value])

  const devicesItem = computed<NavItem>(() => ({ name: 'devices', label: t('nav.devices'), icon: 'pi pi-desktop', to: { name: 'devices' } }))

  const secondaryItems = computed<NavItem[]>(() => [
    { name: 'history', label: t('nav.history'), icon: 'pi pi-history', to: { name: 'history' } },
    { name: 'help', label: t('nav.help'), icon: 'pi pi-question-circle', to: { name: 'help' } },
    { name: 'settings', label: t('nav.settings'), icon: 'pi pi-cog', to: { name: 'settings' } },
  ])

  const socketIndicator = computed(
    () =>
      ({
        connecting: { icon: 'pi pi-spin pi-spinner', label: t('nav.socketConnecting') },
        connected: { icon: 'pi pi-check', label: t('nav.socketConnected') },
        disconnected: { icon: 'pi pi-times', label: t('nav.socketDisconnected') },
      })[deviceSocket.status],
  )

  async function logOut() {
    await auth.signOut()
    workspace.clear()
    await router.push({ name: 'login' })
  }

  return {
    workspaceLabel,
    workspaceMenuItems,
    workspacesDialogVisible,
    home,
    sectionItems,
    mainItems,
    devicesItem,
    secondaryItems,
    socketIndicator,
    logOut,
  }
}
