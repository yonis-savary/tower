<script setup lang="ts">
import { computed, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'
import AppIcon, { PULL_REQUEST_ICON } from './AppIcon.vue'
import { usePullRequestsStore } from '../stores/pullRequests'

const props = defineProps<{ issueKey: string }>()

const { t } = useI18n()
const store = usePullRequestsStore()

const pullRequests = computed(() => store.openForIssue(props.issueKey))
const menu = useTemplateRef<InstanceType<typeof Menu>>('menu')
const menuItems = computed<MenuItem[]>(() =>
  pullRequests.value.map(({ repository, title, url }) => ({ label: `${repository} · ${title}`, url, target: '_blank' })),
)
</script>

<template>
  <a
    v-if="pullRequests.length === 1"
    :href="pullRequests[0]!.url"
    target="_blank"
    rel="noopener noreferrer"
    class="card-action"
    :aria-label="t('jira.openPullRequest')"
    :title="t('jira.openPullRequest')"
    @click.stop
    @keydown.enter.stop
  >
    <AppIcon :icon="PULL_REQUEST_ICON" />
  </a>
  <template v-else-if="pullRequests.length > 1">
    <button
      type="button"
      class="card-action"
      aria-haspopup="true"
      :aria-label="t('jira.openPullRequests', { count: pullRequests.length })"
      :title="t('jira.openPullRequests', { count: pullRequests.length })"
      @click.stop="menu?.toggle($event)"
      @keydown.enter.stop
    >
      <AppIcon :icon="PULL_REQUEST_ICON" />
    </button>
    <Menu ref="menu" :model="menuItems" popup />
  </template>
</template>
