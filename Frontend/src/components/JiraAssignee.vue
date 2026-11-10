<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ name: string | null; avatar: string | null }>()

const { t } = useI18n()

// A broken or blocked avatar falls back on the initials
const avatarFailed = ref(false)
watch(() => props.avatar, () => (avatarFailed.value = false))

const initials = computed(() =>
  (props.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0]!.toUpperCase())
    .join(''),
)
const label = computed(() => props.name ?? t('jira.unassigned'))
</script>

<template>
  <span class="assignee" :title="label" role="img" :aria-label="label">
    <img v-if="avatar && !avatarFailed" :src="avatar" alt="" width="20" height="20" referrerpolicy="no-referrer" @error="avatarFailed = true" />
    <span v-else-if="name" class="initials">{{ initials }}</span>
    <i v-else class="pi pi-user" aria-hidden="true" />
  </span>
</template>

<style scoped>
.assignee {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 20px;
  height: 20px;
  overflow: hidden;
  color: var(--p-text-muted-color);
  background: var(--p-content-hover-background);
  border-radius: 50%;
}

.assignee img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.initials {
  font-size: 0.5625rem;
  font-weight: 600;
}

.assignee .pi {
  font-size: 0.6875rem;
}
</style>
