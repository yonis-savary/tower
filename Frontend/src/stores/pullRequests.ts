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

  async function load(): Promise<void> {
    const [list, reviewers] = await Promise.all([listPullRequests(), listPullRequestApprovals()])
    pullRequests.value = list.data ?? []
    approvals.value = reviewers.data ?? []
    loaded.value = true
  }

  return { pullRequests, approvals, loaded, open, load }
})
