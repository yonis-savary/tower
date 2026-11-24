<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePullRequestsStore } from '../stores/pullRequests'

const props = defineProps<{ issueKey: string }>()

const { t } = useI18n()
const store = usePullRequestsStore()

// Beyond, the line would be too long for a card : the rest is counted
const MAX_THUMBS = 5

const PIPELINES: Record<string, { tone: string; icon: string | null }> = {
  success: { tone: 'success', icon: 'pi pi-check' },
  failed: { tone: 'failed', icon: 'pi pi-times' },
  running: { tone: 'running', icon: 'pi pi-sync' },
  pending: { tone: 'running', icon: 'pi pi-clock' },
  none: { tone: 'running', icon: null },
}

const lines = computed(() =>
  store.openForIssue(props.issueKey).map((pullRequest) => ({
    pullRequest,
    approvals: store.approvalCountOf(pullRequest.id),
    pipeline: PIPELINES[pullRequest.pipeline_status] ?? PIPELINES.none!,
  })),
)
</script>

<template>
  <ul v-if="lines.length" class="pull-requests">
    <li v-for="{ pullRequest, approvals, pipeline } in lines" :key="pullRequest.id">
      <a
        :href="pullRequest.url"
        target="_blank"
        rel="noopener noreferrer"
        :class="['pull-request', pipeline.tone]"
        :title="`${pullRequest.repository} · ${pullRequest.title} · ${t(`mergeRequests.pipeline.${pullRequest.pipeline_status}`)}`"
        @click.stop
        @keydown.enter.stop
      >
        <span class="number">#{{ pullRequest.number }}</span>
        <span class="status">
          <span v-if="approvals" class="approvals" role="img" :aria-label="t('jira.approvals', { count: approvals })">
            <i v-for="index in Math.min(approvals, MAX_THUMBS)" :key="index" class="pi pi-thumbs-up-fill" aria-hidden="true" />
            <template v-if="approvals > MAX_THUMBS">+{{ approvals - MAX_THUMBS }}</template>
          </span>
          <i v-if="pipeline.icon" :class="pipeline.icon" :aria-label="t(`mergeRequests.pipeline.${pullRequest.pipeline_status}`)" />
        </span>
      </a>
    </li>
  </ul>
</template>

<style scoped>
.pull-requests {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin: 0;
  padding: 0.375rem 0 0;
  list-style: none;
  border-top: 1px solid var(--p-content-border-color);
}

.pull-request {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.625rem;
  padding: 0.125rem 0.5rem;
  border-radius: var(--p-content-border-radius);
  font-size: 0.8125rem;
  font-weight: 600;
  text-decoration: none;
  color: var(--tone);
  background: color-mix(in srgb, var(--tone) 12%, transparent);
}

.pull-request:hover {
  background: color-mix(in srgb, var(--tone) 20%, transparent);
}

.pull-request .pi {
  font-size: 0.75rem;
}

.status {
  display: inline-flex;
  align-items: center;
  gap: 0.625rem;
}

.approvals {
  display: inline-flex;
  align-items: center;
  gap: 0.125rem;
}

.success {
  --tone: var(--p-green-600);
}

.failed {
  --tone: var(--p-red-600);
}

.running {
  --tone: var(--p-blue-600);
}
</style>
