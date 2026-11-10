import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  createTaskFromJiraIssue,
  discussJiraIssue,
  getJiraBoard,
  listJiraIssues,
  listJiraIssueTransitions,
  transitionJiraIssue,
  type ListJiraIssuesResponse,
} from '../client'

export type JiraCategory = 'todo' | 'in_progress' | 'done'
export type JiraIssue = Omit<ListJiraIssuesResponse[number], 'status_category'> & { status_category: JiraCategory }
export type JiraTransition = { id: string; name: string; status_id: string; status: string; status_category: JiraCategory }
export type JiraColumn = { name: string; statuses: string[] }
export const JIRA_SEVERITIES: Record<JiraCategory, string> = { todo: 'secondary', in_progress: 'info', done: 'success' }

export const useJiraStore = defineStore('jira', () => {
  const issues = ref<JiraIssue[]>([])
  const columns = ref<JiraColumn[]>([])
  const accountId = ref<string | null>(null)
  const failed = ref(false)

  const byId = computed(() => new Map(issues.value.map((issue) => [issue.id, issue])))
  const tracked = computed(() => issues.value.filter(({ tracked }) => tracked))
  const mine = computed(() => tracked.value.filter(({ assignee_id }) => assignee_id !== null && assignee_id === accountId.value))

  async function load(): Promise<void> {
    const [issueList, board] = await Promise.all([listJiraIssues(), getJiraBoard()])
    failed.value = !issueList.data || !board.data
    if (issueList.data) issues.value = issueList.data as JiraIssue[]
    if (!board.data) return
    columns.value = (board.data.columns ?? []) as JiraColumn[]
    accountId.value = board.data.account_id ?? null
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

  return { issues, columns, accountId, failed, byId, tracked, mine, load, transitionsOf, transition, createTask, discuss }
})
