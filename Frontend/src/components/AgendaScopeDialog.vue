<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import type { RecurrenceScope } from '../utils/agenda'

type Action = 'edit' | 'delete'

const { t } = useI18n()

const visible = ref(false)
const action = ref<Action>('edit')
const scopes = ref<RecurrenceScope[]>([])
let settle: ((scope: RecurrenceScope | null) => void) | null = null

const header = computed(() => t(action.value === 'delete' ? 'agenda.scope.deleteTitle' : 'agenda.scope.editTitle'))

/**
 * Asks which occurrences of a recurring item a change concerns : null when the user gives up.
 */
function ask(asked: Action, offered: RecurrenceScope[] = ['occurrence', 'following', 'series']): Promise<RecurrenceScope | null> {
  settle?.(null)
  action.value = asked
  scopes.value = offered
  visible.value = true

  return new Promise((resolve) => {
    settle = resolve
  })
}

function choose(scope: RecurrenceScope | null) {
  const resolve = settle
  settle = null
  visible.value = false
  resolve?.(scope)
}

defineExpose({ ask })
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :header="header"
    :style="{ width: 'min(24rem, calc(100vw - 2rem))' }"
    @update:visible="(shown: boolean) => { if (!shown) choose(null) }"
  >
    <div class="scopes">
      <Button
        v-for="scope in scopes"
        :key="scope"
        :label="t(`agenda.scope.${scope}`)"
        :severity="action === 'delete' ? 'danger' : undefined"
        :outlined="scope !== scopes[0]"
        fluid
        @click="choose(scope)"
      />
      <Button :label="t('agenda.scope.cancel')" severity="secondary" text fluid @click="choose(null)" />
    </div>
  </Dialog>
</template>

<style scoped>
.scopes {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
</style>
