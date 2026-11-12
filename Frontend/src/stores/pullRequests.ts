import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  listPullRequestApprovals,
  listPullRequests,
  type ListPullRequestApprovalsResponse,
  type ListPullRequestsResponse,
} from '../client'

export type PullRequest = ListPullRequestsResponse[number]

export const usePullRequestsStore = defineStore('pullRequests', () => {
  const pullRequests = ref<ListPullRequestsResponse>([])
  const approvals = ref<ListPullRequestApprovalsResponse>([])
  const loaded = ref(false)

  const open = computed(() => pullRequests.value.filter(({ state }) => state === 'open'))

  /** The open pull requests whose source branch or title names the given Jira key (ABC-12, not ABC-123). */
  function openForIssue(key: string): PullRequest[] {
    const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const pattern = new RegExp(`(^|[^a-z0-9])${escaped}(?![0-9])`, 'i')
    return open.value.filter(({ source_branch, title }) => pattern.test(source_branch) || pattern.test(title))
  }

  async function load(): Promise<void> {
    const [list, reviewers] = await Promise.all([listPullRequests(), listPullRequestApprovals()])
    pullRequests.value = list.data ?? []
    approvals.value = reviewers.data ?? []
    loaded.value = true
  }

  return { pullRequests, approvals, loaded, open, openForIssue, load }
})
