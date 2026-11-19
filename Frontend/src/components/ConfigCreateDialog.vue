<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { createAccessKey, type CreateAccessKeyResponse, type ListAccessKeysResponse } from '../client'
import ConfigDownloads from './ConfigDownloads.vue'
import EventTypeInput from './EventTypeInput.vue'
import HandlerRulesEditor from './HandlerRulesEditor.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { emptyRule, fromForm, scrollToFirstError, toForm, validate, type HandlerRule, type RuleForm } from '../utils/handlerRules'

const visible = defineModel<boolean>('visible', { required: true })
const props = defineProps<{ source?: ListAccessKeysResponse[number] | null }>()
const emit = defineEmits<{ created: [] }>()

const { t } = useI18n()
const workspace = useWorkspaceStore()

const name = ref('')
const listen = ref('*')
const withHandlers = ref(false)
const rules = ref<RuleForm[]>([])
const submitted = ref(false)
const creating = ref(false)
const error = ref<string | null>(null)
// Holds the secret : only kept while the dialog is open, it cannot be read back
const created = ref<CreateAccessKeyResponse | null>(null)

const errors = computed(() => (submitted.value && withHandlers.value ? validate(rules.value) : {}))
const listenPatterns = computed(() => parseListen(listen.value))

function parseListen(value: string): string[] {
  return value.split(/[\s,]+/).filter(Boolean)
}

// A duplicate starts from the listened events and the handlers of its source, with a secret of its own
watch(visible, () => {
  const source = props.source ?? null
  error.value = null
  name.value = source ? t('download.copyName', { name: source.name }).slice(0, 100) : ''
  listen.value = source ? ((source.listen ?? []) as string[]).join(', ') : '*'
  rules.value = source ? toForm((source.handlers ?? []) as HandlerRule[]) : []
  withHandlers.value = rules.value.length > 0
  submitted.value = false
  created.value = null
})

function addHandlers() {
  withHandlers.value = true
  if (!rules.value.length) rules.value.push(emptyRule())
}

async function create() {
  if (!name.value.trim() || creating.value) return
  submitted.value = true
  error.value = null
  if (Object.keys(errors.value).length) {
    error.value = t('handlers.invalid')
    scrollToFirstError()
    return
  }

  creating.value = true
  const handlers = withHandlers.value ? fromForm(rules.value) : []
  const { data } = await createAccessKey({ body: { name: name.value.trim(), listen: listenPatterns.value, handlers } })
  creating.value = false

  if (!data) {
    error.value = t('download.createError')
    return
  }

  created.value = data
  emit('created')
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    :closable="created === null"
    :close-on-escape="created === null"
    :header="created ? t('download.createdTitle') : source ? t('download.duplicateTitle', { name: source.name }) : t('download.createTab')"
    :style="{ width: withHandlers && !created ? '52rem' : '38rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <ConfigDownloads v-if="created" :created="created" />

    <!-- Not a form : Enter in an event field picks a suggestion, it must not create the configuration -->
    <div v-else class="create-form">
      <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
      <p class="description">{{ t('download.configsDescription', { workspace: workspace.current?.name ?? '' }) }}</p>

      <InputText
        v-model="name"
        :placeholder="t('download.namePlaceholder')"
        :aria-label="t('download.namePlaceholder')"
        maxlength="100"
        required
        autofocus
        fluid
        @keydown.enter.prevent="create"
      />
      <div class="field">
        <label for="config-listen">{{ t('download.listenLabel') }}</label>
        <EventTypeInput v-model="listen" input-id="config-listen" list suggest-patterns />
        <small>{{ t('download.listenHint') }}</small>
      </div>

      <div class="field">
        <span class="field-title">{{ t('handlers.title') }}</span>
        <small>{{ t('handlers.description') }}</small>
        <HandlerRulesEditor v-if="withHandlers" v-model="rules" :errors="errors" :listen="listenPatterns" />
        <Button v-else :label="t('handlers.addNow')" icon="pi pi-cog" severity="secondary" outlined class="add-handlers" @click="addHandlers" />
      </div>
    </div>

    <template #footer>
      <Button v-if="created" :label="t('download.closeButton')" @click="visible = false" />
      <template v-else>
        <Button :label="t('handlers.cancel')" severity="secondary" text @click="visible = false" />
        <Button :label="t('download.createButton')" icon="pi pi-plus" :loading="creating" :disabled="!name.trim()" @click="create" />
      </template>
    </template>
  </Dialog>
</template>

<style scoped>
.create-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.description {
  margin: 0;
  color: var(--p-text-muted-color);
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.field small {
  color: var(--p-text-muted-color);
}

.field-title {
  font-weight: 600;
}

.add-handlers {
  align-self: flex-start;
}
</style>
