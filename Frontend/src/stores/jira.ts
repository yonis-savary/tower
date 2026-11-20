import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  createTaskFromJiraIssue,
  discussJiraIssue,
  listJiraIssues,
  listJiraIssueTransitions,
  listJiraProfiles,
  transitionJiraIssue,
  type ListJiraIssuesResponse,
} from '../client'

export type JiraCategory = 'todo' | 'in_progress' | 'done'
// The OpenAPI document reads the profiles from a model linked to none, and cannot type their ids
export type JiraIssue = Omit<ListJiraIssuesResponse[number], 'status_category' | 'profiles'> & { status_category: JiraCategory; profiles: number[] }
export type JiraTransition = { id: string; name: string; status_id: string; status: string; status_category: JiraCategory }
export type JiraColumn = { name: string; statuses: string[] }
export type JiraProfile = { id: number; name: string | null; board: number; board_name: string | null; jql: string | null; columns: JiraColumn[] }
export const JIRA_SEVERITIES: Record<JiraCategory, string> = { todo: 'secondary', in_progress: 'info', done: 'success' }

export const useJiraStore = defineStore('jira', () => {
  const issues = ref<JiraIssue[]>([])
  const profiles = ref<JiraProfile[]>([])
  const accountId = ref<string | null>(null)
  const failed = ref(false)

  const byId = computed(() => new Map(issues.value.map((issue) => [issue.id, issue])))
  const tracked = computed(() => issues.value.filter(({ tracked, parent }) => tracked && parent === null))
  const subtasksOf = computed(() => {
    const subtasks = new Map<number, JiraIssue[]>()
    issues.value
      .filter(({ tracked, parent }) => tracked && parent !== null)
      .sort((a, b) => a.key.localeCompare(b.key, undefined, { numeric: true }))
      .forEach((issue) => subtasks.set(issue.parent!, [...(subtasks.get(issue.parent!) ?? []), issue]))
    return subtasks
  })
  const isMine = ({ assignee_id }: JiraIssue) => assignee_id !== null && assignee_id === accountId.value
  const mine = computed(() => tracked.value.filter(isMine))
  // The last column of a board is its "done", whatever the category Jira gives its statuses
  const doneStatusesOf = computed(() => new Map(profiles.value.map(({ id, columns }) => [id, new Set(columns.at(-1)?.statuses ?? [])])))
  // An issue kept for its task is shown by no profile : any of them may say it is done
  const isDone = (issue: JiraIssue) =>
    issue.status_category === 'done' ||
    (issue.profiles.length ? issue.profiles : profiles.value.map(({ id }) => id)).some((profile) => doneStatusesOf.value.get(profile)?.has(issue.status_id))

  async function load(): Promise<void> {
    const [issueList, profileList] = await Promise.all([listJiraIssues(), listJiraProfiles()])
    failed.value = !issueList.data || !profileList.data
    if (issueList.data) issues.value = issueList.data as JiraIssue[]
    if (!profileList.data) return
    profiles.value = profileList.data.profiles as JiraProfile[]
    accountId.value = profileList.data.account_id ?? null
  }

  async function transitionsOf(issue: number): Promise<JiraTransition[] | null> {
    const { data } = await listJiraIssueTransitions({ path: { issue } })
    return (data as JiraTransition[] | undefined) ?? null
  }

  /** @returns Jira's refusal, null once applied */
  async function transition(issue: number, transitionId: string): Promise<string | null> {
    const { response, error } = await transitionJiraIssue({ path: { issue }, body: { transition: transitionId } })
    await load()
    return response?.ok ? null : (error?.error ?? '')
  }

  async function createTask(issue: number): Promise<boolean> {
    const { response } = await createTaskFromJiraIssue({ path: { issue } })
    return !!response?.ok
  }

  async function discuss(issue: number): Promise<boolean> {
    const { response } = await discussJiraIssue({ path: { issue } })
    return !!response?.ok
  }

  return { issues, profiles, accountId, failed, byId, tracked, subtasksOf, isMine, mine, isDone, load, transitionsOf, transition, createTask, discuss }
})
