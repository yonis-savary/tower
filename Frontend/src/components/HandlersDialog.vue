<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import { updateAccessKeyHandlers, type CreateAccessKeyResponse, type ListAccessKeysResponse } from '../client'
import HandlerRulesEditor from './HandlerRulesEditor.vue'
import { fromForm, toForm, scrollToFirstError, validate, type HandlerRule, type RuleForm } from '../utils/handlerRules'

type AccessKey = ListAccessKeysResponse[number]

/** Edits the handler rules of a configuration. Saving gives it a new secret */
const visible = defineModel<boolean>('visible', { required: true })
const props = defineProps<{ accessKey: AccessKey | null }>()
const emit = defineEmits<{ saved: [CreateAccessKeyResponse] }>()

const { t } = useI18n()

const rules = ref<RuleForm[]>([])
const submitted = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const errors = computed(() => (submitted.value ? validate(rules.value) : {}))
const listen = computed(() => (props.accessKey?.listen ?? []) as string[])

watch(visible, (open) => {
  if (!open) return
  rules.value = toForm((props.accessKey?.handlers ?? []) as HandlerRule[])
  submitted.value = false
  error.value = null
})

async function save() {
  const key = props.accessKey
  if (!key) return

  submitted.value = true
  error.value = null
  if (Object.keys(errors.value).length) {
    error.value = t('handlers.invalid')
    scrollToFirstError()
    return
  }
  if (!window.confirm(t('handlers.saveConfirm', { name: key.name }))) return

  saving.value = true
  const { data } = await updateAccessKeyHandlers({ path: { key: key.id }, body: { handlers: fromForm(rules.value) } })
  saving.value = false

  if (!data) {
    error.value = t('handlers.saveError')
    return
  }

  visible.value = false
  emit('saved', data)
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :header="t('handlers.editTitle', { name: accessKey?.name ?? '' })"
    :style="{ width: '52rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <div class="content">
      <p class="description">{{ t('handlers.description') }}</p>
      <Message severity="info" :closable="false">{{ t('handlers.rotationNotice') }}</Message>
      <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
      <HandlerRulesEditor v-model="rules" :errors="errors" :listen="listen" />
    </div>

    <template #footer>
      <Button :label="t('handlers.cancel')" severity="secondary" text @click="visible = false" />
      <Button :label="t('handlers.save')" icon="pi pi-check" :loading="saving" @click="save" />
    </template>
  </Dialog>
</template>

<style scoped>
.content {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.description {
  margin: 0;
  color: var(--p-text-muted-color);
}
</style>
