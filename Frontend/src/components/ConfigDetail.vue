<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import {
  rotateAccessKeySecret,
  updateAccessKeyHandlers,
  updateAccessKeyListen,
  type CreateAccessKeyResponse,
  type ListAccessKeysResponse,
} from '../client'
import EventTypeInput from './EventTypeInput.vue'
import HandlerRulesEditor from './HandlerRulesEditor.vue'
import { emptyRule, fromForm, scrollToFirstError, toForm, validate, type HandlerRule, type RuleForm } from '../utils/handlerRules'

type AccessKey = ListAccessKeysResponse[number]

const props = defineProps<{ accessKey: AccessKey }>()
const emit = defineEmits<{ changed: []; rotated: [CreateAccessKeyResponse] }>()

const { t, d } = useI18n()

const listen = ref('')
const savingListen = ref(false)
const rules = ref<RuleForm[]>([])
const withHandlers = ref(false)
const submitted = ref(false)
const savingHandlers = ref(false)
const rotating = ref(false)
const error = ref<string | null>(null)

const storedListen = computed(() => ((props.accessKey.listen ?? []) as string[]).join(', '))
const storedHandlers = computed(() => (props.accessKey.handlers ?? []) as HandlerRule[])
const listenPatterns = computed(() => parseListen(listen.value))
const listenChanged = computed(() => listenPatterns.value.join(',') !== parseListen(storedListen.value).join(','))
const handlersChanged = computed(() => JSON.stringify(fromForm(rules.value)) !== JSON.stringify(fromForm(toForm(storedHandlers.value))))
const errors = computed(() => (submitted.value ? validate(rules.value) : {}))

function parseListen(value: string): string[] {
  return value.split(/[\s,]+/).filter(Boolean)
}

// Each draft is reset only when what it edits changes : saving the other one keeps it
watch(() => [props.accessKey.id, storedListen.value], () => {
  listen.value = storedListen.value
}, { immediate: true })

function resetHandlers() {
  rules.value = toForm(storedHandlers.value)
  withHandlers.value = rules.value.length > 0
  submitted.value = false
}

watch(() => [props.accessKey.id, JSON.stringify(storedHandlers.value)], resetHandlers, { immediate: true })

watch(() => props.accessKey.id, () => (error.value = null))

function addHandlers() {
  withHandlers.value = true
  if (!rules.value.length) rules.value.push(emptyRule())
}

async function saveListen() {
  savingListen.value = true
  error.value = null
  const { response } = await updateAccessKeyListen({ path: { key: props.accessKey.id }, body: { listen: listenPatterns.value } })
  savingListen.value = false

  if (!response?.ok) {
    error.value = t('download.listenError')
    return
  }

  emit('changed')
}

async function saveHandlers() {
  submitted.value = true
  error.value = null
  if (Object.keys(errors.value).length) {
    error.value = t('handlers.invalid')
    scrollToFirstError()
    return
  }
  if (!window.confirm(t('handlers.saveConfirm', { name: props.accessKey.name }))) return

  savingHandlers.value = true
  const { data } = await updateAccessKeyHandlers({ path: { key: props.accessKey.id }, body: { handlers: fromForm(rules.value) } })
  savingHandlers.value = false

  if (!data) {
    error.value = t('handlers.saveError')
    return
  }

  emit('rotated', data)
}

async function rotate() {
  if (!window.confirm(t('handlers.rotateConfirm', { name: props.accessKey.name }))) return

  rotating.value = true
  error.value = null
  const { data } = await rotateAccessKeySecret({ path: { key: props.accessKey.id } })
  rotating.value = false

  if (!data) {
    error.value = t('handlers.rotateError')
    return
  }

  emit('rotated', data)
}
</script>

<template>
  <div class="config-detail">
    <header class="detail-header">
      <div class="identity">
        <h3>{{ accessKey.name }}</h3>
        <code>{{ accessKey.client_id }}</code>
        <small>
          {{ accessKey.last_used_at ? t('download.lastUsed', { date: d(new Date(accessKey.last_used_at), 'short') }) : t('download.neverUsed') }}
        </small>
      </div>
      <Button :label="t('handlers.rotate')" icon="pi pi-refresh" severity="secondary" outlined size="small" :loading="rotating" @click="rotate" />
    </header>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <form class="section" @submit.prevent="saveListen">
      <label class="section-title" for="config-detail-listen">{{ t('download.listenLabel') }}</label>
      <EventTypeInput v-model="listen" input-id="config-detail-listen" list suggest-patterns />
      <small>{{ t('download.listenHint') }}</small>
      <div v-if="listenChanged" class="section-actions">
        <Button :label="t('download.listenCancel')" severity="secondary" text size="small" @click="listen = storedListen" />
        <Button type="submit" :label="t('download.listenSave')" size="small" :loading="savingListen" />
      </div>
    </form>

    <div class="section">
      <span class="section-title">{{ t('handlers.title') }}</span>
      <small>{{ t('handlers.description') }}</small>
      <template v-if="withHandlers">
        <Message severity="info" size="small" :closable="false">{{ t('handlers.rotationNotice') }}</Message>
        <HandlerRulesEditor v-model="rules" :errors="errors" :listen="parseListen(storedListen)" />
        <div v-if="handlersChanged" class="section-actions">
          <Button :label="t('handlers.cancel')" severity="secondary" text size="small" @click="resetHandlers" />
          <Button :label="t('handlers.save')" icon="pi pi-check" size="small" :loading="savingHandlers" @click="saveHandlers" />
        </div>
      </template>
      <Button v-else :label="t('handlers.addNow')" icon="pi pi-cog" severity="secondary" outlined size="small" class="add-handlers" @click="addHandlers" />
    </div>
  </div>
</template>

<style scoped>
.config-detail {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  min-width: 0;
}

.detail-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.identity {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  min-width: 0;
}

.identity h3 {
  margin: 0;
  font-size: 1.0625rem;
  font-weight: 600;
  overflow-wrap: anywhere;
}

.identity code {
  font-size: 0.8125rem;
  overflow-wrap: anywhere;
}

.identity small,
.section small {
  color: var(--p-text-muted-color);
}

.section {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  padding-top: 1rem;
  border-top: 1px solid var(--p-content-border-color);
}

.section-title {
  font-weight: 600;
}

.section-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}

.add-handlers {
  align-self: flex-start;
}
</style>
