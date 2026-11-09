<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Tag from 'primevue/tag'
import {
  listPullRequestApprovals,
  listPullRequests,
  type ListPullRequestApprovalsResponse,
  type ListPullRequestsResponse,
} from '../client'
import { useWorkspaceStore } from '../stores/workspace'

type PullRequest = ListPullRequestsResponse[number]
type Row = { pullRequest: PullRequest; depth: number }

const pipelineIcons: Record<string, string> = {
  success: 'pi-check-circle',
  failed: 'pi-times-circle',
  running: 'pi-spin pi-spinner',
  pending: 'pi-clock',
}

const { t } = useI18n()
const workspace = useWorkspaceStore()

const pullRequests = ref<ListPullRequestsResponse>([])
const approvals = ref<ListPullRequestApprovalsResponse>([])
const loaded = ref(false)

async function load() {
  const [list, reviewers] = await Promise.all([listPullRequests(), listPullRequestApprovals()])
  pullRequests.value = list.data ?? []
  approvals.value = reviewers.data ?? []
  loaded.value = true
}

watch(() => workspace.current?.id, (id) => { if (id) void load() }, { immediate: true })

/** A pull request is the child of the open one whose source branch it targets. */
function toRows(open: PullRequest[]): Row[] {
  const sources = new Set(open.map(({ source_branch }) => source_branch))
  const rows: Row[] = []
  const visited = new Set<number>()

  function visit(pullRequest: PullRequest, depth: number) {
    if (visited.has(pullRequest.id)) return

    visited.add(pullRequest.id)
    rows.push({ pullRequest, depth })
    open
      .filter(({ target_branch }) => target_branch === pullRequest.source_branch)
      .forEach((child) => visit(child, depth + 1))
  }

  open.filter(({ target_branch }) => !sources.has(target_branch)).forEach((root) => visit(root, 0))
  // Branches targeting each other have no root : they must still be listed
  open.forEach((pullRequest) => visit(pullRequest, 0))

  return rows
}

const repositories = computed(() => {
  const open = pullRequests.value.filter(({ state }) => state === 'open')
  const names = [...new Set(open.map(({ repository }) => repository))].sort()

  return names.map((name) => ({ name, rows: toRows(open.filter(({ repository }) => repository === name)) }))
})

const closed = computed(() => pullRequests.value.filter(({ state }) => state !== 'open'))

function reviewersOf(id: number) {
  return approvals.value.filter(({ pull_request }) => pull_request === id)
}

function approvedCount(id: number): number {
  return reviewersOf(id).filter(({ status }) => status === 'approved').length
}

function reviewersTitle(id: number): string {
  return reviewersOf(id)
    .map(({ display_name, status }) => `${display_name} : ${t(`mergeRequests.review.${status}`)}`)
    .join('\n')
}
</script>

<template>
  <h1 class="page-title">{{ t('nav.mergeRequests') }}</h1>

  <p v-if="loaded && !pullRequests.length" class="empty">
    {{ t('mergeRequests.empty') }}
    <RouterLink :to="{ name: 'settings' }">{{ t('nav.settings') }}</RouterLink>
  </p>

  <section v-for="repository in repositories" :key="repository.name" class="panel">
    <h2>{{ repository.name }}</h2>
    <ul class="pull-requests">
      <li
        v-for="{ pullRequest, depth } in repository.rows"
        :key="pullRequest.id"
        class="pull-request"
        :data-depth="depth"
        :style="{ '--depth': depth }"
      >
        <i v-if="depth" class="pi pi-angle-right pull-request-branch" aria-hidden="true" />
        <div class="pull-request-main">
          <a :href="pullRequest.url" target="_blank" rel="noopener noreferrer">{{ pullRequest.title }}</a>
          <small>
            <code>{{ pullRequest.source_branch }}</code> → <code>{{ pullRequest.target_branch }}</code>
            · {{ pullRequest.author }}
          </small>
        </div>
        <div class="pull-request-meta">
          <Tag v-if="pullRequest.draft" :value="t('mergeRequests.draft')" severity="secondary" />
          <span v-if="reviewersOf(pullRequest.id).length" class="meta-item" :title="reviewersTitle(pullRequest.id)">
            <i class="pi pi-thumbs-up" aria-hidden="true" />
            {{ approvedCount(pullRequest.id) }}/{{ reviewersOf(pullRequest.id).length }}
          </span>
          <span class="meta-item" :title="t('mergeRequests.comments')">
            <i class="pi pi-comment" aria-hidden="true" /> {{ pullRequest.comment_count }}
          </span>
          <i
            v-if="pipelineIcons[pullRequest.pipeline_status]"
            :class="['pi', pipelineIcons[pullRequest.pipeline_status], `pipeline-${pullRequest.pipeline_status}`]"
            role="img"
            :aria-label="t(`mergeRequests.pipeline.${pullRequest.pipeline_status}`)"
            :title="t(`mergeRequests.pipeline.${pullRequest.pipeline_status}`)"
          />
        </div>
      </li>
    </ul>
  </section>

  <section v-if="closed.length" class="panel">
    <h2>{{ t('mergeRequests.closedTitle') }}</h2>
    <ul class="pull-requests">
      <li v-for="pullRequest in closed" :key="pullRequest.id" class="pull-request closed-pull-request">
        <div class="pull-request-main">
          <a :href="pullRequest.url" target="_blank" rel="noopener noreferrer">{{ pullRequest.title }}</a>
          <small>{{ pullRequest.repository }} · {{ pullRequest.author }}</small>
        </div>
        <Tag :value="t(`mergeRequests.state.${pullRequest.state}`)" :severity="pullRequest.state === 'merged' ? 'success' : 'secondary'" />
      </li>
    </ul>
  </section>
</template>

<style scoped>
.page-title {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.panel {
  max-width: 60rem;
  margin-bottom: 1.25rem;
  padding: 1.25rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel h2 {
  margin: 0 0 0.75rem;
  font-size: 1.0625rem;
  font-weight: 600;
  overflow-wrap: anywhere;
}

.empty {
  margin: 0;
  color: var(--p-text-muted-color);
}

.pull-requests {
  margin: 0;
  padding: 0;
  list-style: none;
}

.pull-request {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem 0.75rem;
  /* Bounded : a deep stack must not push the text out of a narrow screen */
  padding: 0.625rem 0 0.625rem calc(min(var(--depth, 0), 4) * 1.25rem);
  border-top: 1px solid var(--p-content-border-color);
}

.pull-request:first-child {
  border-top: 0;
}

.pull-request-branch {
  color: var(--p-text-muted-color);
}

.pull-request-main {
  flex: 1 1 14rem;
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  min-width: 0;
  overflow-wrap: anywhere;
}

.pull-request-main small {
  color: var(--p-text-muted-color);
}

.pull-request-main code {
  font-size: 0.8125rem;
}

.pull-request-meta {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-left: auto;
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

.meta-item {
  white-space: nowrap;
}

.pipeline-success {
  color: light-dark(var(--p-green-600), var(--p-green-400));
}

.pipeline-failed {
  color: light-dark(var(--p-red-600), var(--p-red-400));
}

.pipeline-running,
.pipeline-pending {
  color: light-dark(var(--p-blue-600), var(--p-blue-400));
}
</style>
