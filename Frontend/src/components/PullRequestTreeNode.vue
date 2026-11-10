<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ListPullRequestApprovalsResponse, ListPullRequestsResponse } from '../client'
import AppIcon, { PULL_REQUEST_ICON } from './AppIcon.vue'

const props = defineProps<{ pullRequest: ListPullRequestsResponse[number]; reviewers: ListPullRequestApprovalsResponse }>()

const { t } = useI18n()

const PIPELINE_ICONS: Record<string, string> = {
  success: 'pi-check',
  failed: 'pi-times',
  running: 'pi-spin pi-spinner',
  pending: 'pi-clock',
}
// Around the circle, in degrees from the right, clockwise. The top-left corner holds the comment count
const APPROVAL_ANGLES: Record<number, number[]> = { 1: [-45], 2: [-45, 45], 3: [-45, 45, 135] }
const APPROVAL_RADIUS = 28

const approved = computed(() => props.reviewers.filter(({ status }) => status === 'approved').length)
const approvalAngles = computed(() => APPROVAL_ANGLES[Math.min(approved.value, 3)] ?? [])
const shortBranch = computed(() => props.pullRequest.source_branch.replace(/.+\//, ''))

const title = computed(() =>
  [
    props.pullRequest.title,
    `${props.pullRequest.source_branch} → ${props.pullRequest.target_branch}`,
    t(`mergeRequests.pipeline.${props.pullRequest.pipeline_status}`),
    ...props.reviewers.map(({ display_name, status }) => `${display_name} : ${t(`mergeRequests.review.${status}`)}`),
    `${props.pullRequest.comment_count} ${t('mergeRequests.comments').toLowerCase()}`,
  ].join('\n'),
)

function approvalStyle(angle: number) {
  const radians = (angle * Math.PI) / 180
  return { transform: `translate(${(Math.cos(radians) * APPROVAL_RADIUS).toFixed(1)}px, ${(Math.sin(radians) * APPROVAL_RADIUS).toFixed(1)}px)` }
}
</script>

<template>
  <div class="node">
    <a
      :href="pullRequest.url"
      target="_blank"
      rel="noopener noreferrer"
      :class="['circle', `pipeline-${pullRequest.pipeline_status}`, { draft: pullRequest.draft }]"
      :title="title"
      :aria-label="title"
    >
      <AppIcon :icon="PIPELINE_ICONS[pullRequest.pipeline_status] ? `pi ${PIPELINE_ICONS[pullRequest.pipeline_status]}` : PULL_REQUEST_ICON" />
      <span v-if="pullRequest.comment_count" class="comments" aria-hidden="true">{{ pullRequest.comment_count }}</span>
      <span v-for="(angle, index) in approvalAngles" :key="index" class="approval" :style="approvalStyle(angle)" aria-hidden="true">
        <i class="pi pi-thumbs-up" />
      </span>
    </a>
    <small :title="pullRequest.source_branch">{{ shortBranch }}</small>
  </div>
</template>

<style scoped>
.node {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.375rem;
}

.circle {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  color: var(--p-text-muted-color);
  background: var(--p-content-background);
  border: 2px solid currentColor;
  border-radius: 50%;
  text-decoration: none;
  transition: transform 0.15s ease;
}

.circle:hover {
  transform: scale(1.1);
}

.circle:focus-visible {
  outline: 2px solid var(--p-primary-color);
  outline-offset: 2px;
}

.circle.draft {
  border-style: dashed;
  opacity: 0.7;
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

.comments {
  position: absolute;
  top: -6px;
  left: -6px;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  color: var(--p-primary-contrast-color);
  background: var(--p-primary-color);
  border-radius: 9px;
  font-size: 0.6875rem;
  font-weight: 600;
  line-height: 18px;
  text-align: center;
}

.approval {
  position: absolute;
  top: 50%;
  left: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  margin: -9px 0 0 -9px;
  color: light-dark(var(--p-green-700), var(--p-green-300));
  background: var(--p-content-background);
  border: 1px solid currentColor;
  border-radius: 50%;
  pointer-events: none;
}

.approval .pi {
  font-size: 0.625rem;
}

small {
  max-width: 100%;
  overflow: hidden;
  color: var(--p-text-muted-color);
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
