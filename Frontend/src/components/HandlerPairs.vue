<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import { LIMITS, nextId, type Pair, type RuleErrors } from '../utils/handlerRules'

/** Fields of a JSON object, one level deep : a response or the payload of a next event */
const pairs = defineModel<Pair[]>({ required: true })
defineProps<{ errors: RuleErrors; addLabel: string }>()

const { t } = useI18n()

function add() {
  pairs.value.push({ id: nextId(), key: '', value: '' })
}

function remove(id: number) {
  pairs.value = pairs.value.filter((pair) => pair.id !== id)
}
</script>

<template>
  <div class="pairs">
    <div v-for="pair in pairs" :key="pair.id" class="pair">
      <InputText
        v-model="pair.key"
        :placeholder="t('handlers.pairKey')"
        :aria-label="t('handlers.pairKey')"
        :invalid="!!errors[`${pair.id}.key`]"
        :maxlength="LIMITS.key"
        size="small"
        class="pair-key"
      />
      <InputText
        v-model="pair.value"
        :placeholder="t('handlers.pairValue')"
        :aria-label="t('handlers.pairValue')"
        :invalid="!!errors[`${pair.id}.value`]"
        :maxlength="LIMITS.value"
        size="small"
        class="pair-value"
      />
      <Button icon="pi pi-times" severity="secondary" text rounded size="small" :aria-label="t('handlers.remove')" @click="remove(pair.id)" />
      <small v-if="errors[`${pair.id}.key`]" class="field-error">{{ t(`handlers.error.${errors[`${pair.id}.key`]}`) }}</small>
    </div>
    <Button v-if="pairs.length < LIMITS.pairs" :label="addLabel" icon="pi pi-plus" severity="secondary" text size="small" class="add" @click="add" />
  </div>
</template>

<style scoped>
.pairs {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.pair {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.375rem;
}

.pair-key {
  flex: 1 1 7rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.pair-value {
  flex: 2 1 10rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.field-error {
  flex-basis: 100%;
  color: var(--p-red-500);
}

.add {
  align-self: flex-start;
}
</style>
