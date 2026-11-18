<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { SprintLink } from '../stores/tasks'
import { hueOf, linkLabel } from '../utils/sprintLinks'

const props = defineProps<{ link: SprintLink }>()

const failed = ref(false)
// The icon belongs to the site : a changed address may have another one
watch(() => props.link.url, () => (failed.value = false))

const initial = computed(() => linkLabel(props.link).charAt(0).toUpperCase())
const color = computed(() => `hsl(${hueOf(props.link.url)} 65% 45%)`)
// The browser keeps an icon for a day : an edited address must not show the previous site's
const source = computed(() => `/api/sprints/links/${props.link.id}/icon?v=${encodeURIComponent(props.link.url)}`)
</script>

<template>
  <img
    v-if="!failed"
    class="link-icon"
    :src="source"
    alt=""
    loading="lazy"
    @error="failed = true"
  />
  <span v-else class="link-icon fallback" :style="{ background: color }" aria-hidden="true">{{ initial }}</span>
</template>

<style scoped>
.link-icon {
  flex: none;
  width: 1rem;
  height: 1rem;
  border-radius: 0.25rem;
  object-fit: contain;
}

.fallback {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 0.625rem;
  font-weight: 700;
  line-height: 1;
}
</style>
