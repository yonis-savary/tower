import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  createTaskFromJiraIssue,
  discussJiraIssue,
  listJiraColumns,
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
  const failed = ref(false)

  const byId = computed(() => new Map(issues.value.map((issue) => [issue.id, issue])))
  const tracked = computed(() => issues.value.filter(({ tracked }) => tracked))

  async function load(): Promise<void> {
    const [issueList, columnList] = await Promise.all([listJiraIssues(), listJiraColumns()])
    failed.value = !issueList.data || !columnList.data
    if (issueList.data) issues.value = issueList.data as JiraIssue[]
    if (columnList.data) columns.value = columnList.data as JiraColumn[]
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

  return { issues, columns, failed, byId, tracked, load, transitionsOf, transition, createTask, discuss }
})
