<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { useToast } from 'primevue/usetoast'
import {
  createNotificationRule,
  deleteNotificationRule,
  deletePushSubscription,
  listNotificationRules,
  previewNotification,
  testPushSubscription,
  updateNotificationRule,
  type ListNotificationRulesResponse,
  type ListPushSubscriptionsResponse,
} from '../client'
import EventFields from './EventFields.vue'
import EventTypeInput from './EventTypeInput.vue'
import { usePushStore } from '../stores/push'
import { useWorkspaceStore } from '../stores/workspace'
import { describeUserAgent } from '../utils/push'

type Rule = ListNotificationRulesResponse[number]
type Subscription = ListPushSubscriptionsResponse[number]
type TextMode = 'default' | 'raw' | 'key'
type Text = { mode: 'raw' | 'key'; value: string } | null

const { t, d } = useI18n()
const toast = useToast()
const push = usePushStore()
const workspace = useWorkspaceStore()

const rules = ref<ListNotificationRulesResponse>([])
const loaded = ref(false)
const error = ref<string | null>(null)
const switching = ref(false)

// The form serves the creation (`editing` null) and the edition
const formVisible = ref(false)
const editing = ref<Rule | null>(null)
const form = ref({
  name: '',
  listen: '',
  titleMode: 'default' as TextMode,
  title: '',
  bodyMode: 'default' as TextMode,
  body: '',
  // An example event, only for the preview
  exampleType: '',
  examplePayload: '',
})
const saving = ref(false)
const formError = ref<string | null>(null)
const preview = ref<{ title: string; body: string } | null>(null)

const modeOptions = computed(() => [
  { value: 'default', label: t('notifications.mode.default') },
  { value: 'raw', label: t('notifications.mode.raw') },
  { value: 'key', label: t('notifications.mode.key') },
])

async function loadRules() {
  error.value = null
  const { data } = await listNotificationRules()
  loaded.value = true
  if (data) rules.value = data
  else error.value = t('notifications.loadError')
}

watch(() => workspace.current?.id, (id) => { if (id) void Promise.all([loadRules(), push.loadSubscriptions()]) }, { immediate: true })

// Browsers

async function enable() {
  switching.value = true
  error.value = null
  const enabled = await push.enable().catch(() => false)
  switching.value = false
  if (!enabled && push.state !== 'denied') error.value = t('notifications.enableError')
}

async function disable() {
  switching.value = true
  await push.disable().catch(() => undefined)
  switching.value = false
}

function browserName(subscription: Subscription): string {
  return describeUserAgent(subscription.user_agent) ?? t('notifications.unknownBrowser')
}

async function test(subscription: Subscription) {
  error.value = null
  const { response } = await testPushSubscription({
    path: { subscription: subscription.id },
    body: { title: 'Tower', body: t('notifications.testBody') },
  })
  await push.loadSubscriptions()

  if (response?.ok) toast.add({ severity: 'success', summary: t('notifications.testSent'), life: 3000 })
  else error.value = t('notifications.testError')
}

async function forget(subscription: Subscription) {
  if (subscription.endpoint === push.endpoint) return disable()
  if (!window.confirm(t('notifications.forgetConfirm', { browser: browserName(subscription), workspace: workspace.current?.name }))) return

  await deletePushSubscription({ path: { subscription: subscription.id } })
  await push.loadSubscriptions()
}

// Rules

function textOf(mode: TextMode, value: string): Text {
  return mode === 'default' || !value.trim() ? null : { mode, value: value.trim() }
}

// Read back from the server, which only stores these two modes
function storedText(text: { mode: string; value: string } | null): Text {
  return text && (text.mode === 'raw' || text.mode === 'key') ? { mode: text.mode, value: text.value } : null
}

function describeText(stored: { mode: string; value: string } | null, fallback: string): string {
  const text = storedText(stored)
  if (!text) return fallback
  return text.mode === 'key' ? `payload.${text.value}` : `“${text.value}”`
}

function parseListen(value: string): string[] {
  return value.split(/[\s,]+/).filter(Boolean)
}

// A type matching the first pattern, for the preview
function exampleTypeOf(listen: string[]): string {
  const pattern = listen[0] ?? '*'
  return pattern === '*' ? 'example' : pattern.replace(/\*/g, 'example').replace(/\?/g, 'x')
}

function openForm(rule: Rule | null) {
  editing.value = rule
  form.value = {
    name: rule?.name ?? '',
    listen: rule?.listen.join(' ') ?? '',
    titleMode: (rule?.title?.mode as TextMode) ?? 'default',
    title: rule?.title?.value ?? '',
    bodyMode: (rule?.body?.mode as TextMode) ?? 'default',
    body: rule?.body?.value ?? '',
    exampleType: exampleTypeOf(rule?.listen ?? []),
    examplePayload: '{ "title": "Fix the login form", "author": { "name": "Alice" } }',
  }
  formError.value = null
  preview.value = null
  formVisible.value = true
}

function rulePayload() {
  return {
    name: form.value.name.trim(),
    listen: parseListen(form.value.listen),
    title: textOf(form.value.titleMode, form.value.title),
    body: textOf(form.value.bodyMode, form.value.body),
  }
}

async function save() {
  saving.value = true
  formError.value = null

  const target = editing.value
  const body = rulePayload()
  const { response } = target
    ? await updateNotificationRule({ path: { rule: target.id }, body: { ...body, enabled: target.enabled } })
    : await createNotificationRule({ body })
  saving.value = false

  if (!response?.ok) {
    formError.value = response?.status === 422 ? t('notifications.invalid') : t('notifications.saveError')
    return
  }

  formVisible.value = false
  await loadRules()
}

async function setEnabled(rule: Rule, enabled: boolean) {
  error.value = null
  const { name, listen } = rule
  const { data } = await updateNotificationRule({ path: { rule: rule.id }, body: { name, listen, title: storedText(rule.title), body: storedText(rule.body), enabled } })
  if (!data) error.value = t('notifications.saveError')
  await loadRules()
}

async function remove(rule: Rule) {
  if (!window.confirm(t('notifications.deleteConfirm', { name: rule.name }))) return

  error.value = null
  const { response } = await deleteNotificationRule({ path: { rule: rule.id } })
  if (!response?.ok) error.value = t('notifications.deleteError')
  await loadRules()
}

// The preview follows the form, a little after each change
let previewTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => (formVisible.value ? { ...form.value } : null),
  (values) => {
    clearTimeout(previewTimer)
    if (!values) return

    previewTimer = setTimeout(async () => {
      const { data } = await previewNotification({
        body: {
          title: textOf(values.titleMode, values.title),
          body: textOf(values.bodyMode, values.body),
          type: values.exampleType.trim(),
          payload: values.examplePayload.trim() || '{}',
        },
      })
      preview.value = data ? { title: data.title ?? '', body: data.body ?? '' } : null
    }, 300)
  },
  { deep: true },
)
</script>

<template>
  <Message v-if="error" class="panel-error" severity="error" :closable="false">{{ error }}</Message>

  <section class="panel">
    <header class="panel-header">
      <div>
        <h2 class="panel-title">{{ t('notifications.browserTitle') }}</h2>
        <p class="description">{{ t(`notifications.state.${push.state}`, { workspace: workspace.current?.name }) }}</p>
      </div>
      <Button
        v-if="push.state === 'disabled'"
        :label="t('notifications.enable')"
        icon="pi pi-bell"
        size="small"
        :loading="switching"
        @click="enable"
      />
      <Button
        v-else-if="push.state === 'enabled'"
        :label="t('notifications.disable')"
        icon="pi pi-bell-slash"
        severity="secondary"
        size="small"
        :loading="switching"
        @click="disable"
      />
    </header>

    <div v-if="push.subscriptions?.length" class="table-wrapper">
      <table class="list">
        <thead>
          <tr>
            <th>{{ t('notifications.browser') }}</th>
            <th>{{ t('notifications.lastSuccess') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="subscription in push.subscriptions" :key="subscription.id">
            <td>
              {{ browserName(subscription) }}
              <Tag v-if="subscription.endpoint === push.endpoint" class="this-browser" :value="t('notifications.thisBrowser')" severity="info" />
            </td>
            <td class="muted">
              {{ subscription.last_success_at ? d(new Date(subscription.last_success_at), 'short') : t('notifications.never') }}
            </td>
            <td>
              <div class="buttons">
                <Button icon="pi pi-send" severity="secondary" text size="small" :aria-label="t('notifications.test')" :title="t('notifications.test')" @click="test(subscription)" />
                <Button icon="pi pi-trash" severity="secondary" text size="small" :aria-label="t('notifications.forget')" :title="t('notifications.forget')" @click="forget(subscription)" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <header class="panel-header">
      <div>
        <h2 class="panel-title">{{ t('notifications.rulesTitle') }}</h2>
        <p class="description">{{ t('notifications.rulesDescription') }}</p>
      </div>
      <Button :label="t('notifications.new')" icon="pi pi-plus" size="small" @click="openForm(null)" />
    </header>

    <p v-if="loaded && !rules.length" class="muted empty">{{ t('notifications.empty') }}</p>

    <div v-else-if="rules.length" class="table-wrapper">
      <table class="list">
        <thead>
          <tr>
            <th>{{ t('notifications.enabled') }}</th>
            <th>{{ t('notifications.nameLabel') }}</th>
            <th>{{ t('notifications.listenLabel') }}</th>
            <th>{{ t('notifications.titleLabel') }}</th>
            <th>{{ t('notifications.bodyLabel') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="rule in rules" :key="rule.id" :class="{ disabled: !rule.enabled }">
            <td>
              <ToggleSwitch
                :model-value="rule.enabled"
                :aria-label="rule.enabled ? t('notifications.disableRule') : t('notifications.enableRule')"
                @update:model-value="setEnabled(rule, $event)"
              />
            </td>
            <td class="name"><strong>{{ rule.name }}</strong></td>
            <td>
              <span class="patterns">
                <code v-for="pattern in rule.listen" :key="pattern" class="pattern">{{ pattern }}</code>
              </span>
            </td>
            <td>{{ describeText(rule.title, t('notifications.defaultTitle')) }}</td>
            <td>{{ describeText(rule.body, t('notifications.defaultBody')) }}</td>
            <td>
              <div class="buttons">
                <Button icon="pi pi-pencil" severity="secondary" text size="small" :aria-label="t('notifications.edit')" :title="t('notifications.edit')" @click="openForm(rule)" />
                <Button icon="pi pi-trash" severity="secondary" text size="small" :aria-label="t('notifications.deleteButton')" :title="t('notifications.deleteButton')" @click="remove(rule)" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <Dialog
    v-model:visible="formVisible"
    modal
    :header="editing ? t('notifications.edit') : t('notifications.new')"
    :style="{ width: '34rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <form class="rule-form" @submit.prevent="save">
      <Message v-if="formError" severity="error" :closable="false">{{ formError }}</Message>

      <div class="field">
        <label for="rule-name">{{ t('notifications.nameLabel') }}</label>
        <InputText id="rule-name" v-model="form.name" maxlength="100" required autofocus fluid />
      </div>

      <div class="field">
        <label for="rule-listen">{{ t('notifications.listenLabel') }}</label>
        <EventTypeInput v-model="form.listen" input-id="rule-listen" placeholder="task.* pr.pipeline.failed" list suggest-patterns required />
        <small>{{ t('notifications.listenHint') }}</small>
      </div>

      <div class="field">
        <span id="rule-title-mode" class="field-label">{{ t('notifications.titleLabel') }}</span>
        <SelectButton v-model="form.titleMode" :options="modeOptions" option-label="label" option-value="value" :allow-empty="false" aria-labelledby="rule-title-mode" />
        <InputText
          v-if="form.titleMode !== 'default'"
          v-model="form.title"
          :class="{ monospace: form.titleMode === 'key' }"
          :placeholder="form.titleMode === 'key' ? 'author.name' : t('notifications.rawPlaceholder')"
          :aria-label="t('notifications.titleLabel')"
          maxlength="200"
          required
          fluid
        />
        <small v-else>{{ t('notifications.defaultTitleHint') }}</small>
      </div>

      <div class="field">
        <span id="rule-body-mode" class="field-label">{{ t('notifications.bodyLabel') }}</span>
        <SelectButton v-model="form.bodyMode" :options="modeOptions" option-label="label" option-value="value" :allow-empty="false" aria-labelledby="rule-body-mode" />
        <InputText
          v-if="form.bodyMode !== 'default'"
          v-model="form.body"
          :class="{ monospace: form.bodyMode === 'key' }"
          :placeholder="form.bodyMode === 'key' ? 'title' : t('notifications.rawPlaceholder')"
          :aria-label="t('notifications.bodyLabel')"
          maxlength="200"
          required
          fluid
        />
        <small v-else>{{ t('notifications.defaultBodyHint') }}</small>
      </div>
      <small class="muted">{{ t('notifications.keyHint') }}</small>

      <fieldset class="preview">
        <legend>{{ t('notifications.preview') }}</legend>
        <EventFields v-model:type="form.exampleType" v-model:payload="form.examplePayload" />
        <div class="notification" aria-live="polite">
          <img src="/icon-192.png" alt="" class="notification-icon" />
          <div class="notification-text">
            <strong>{{ preview?.title ?? '…' }}</strong>
            <span>{{ preview ? preview.body : t('notifications.previewUnavailable') }}</span>
          </div>
        </div>
      </fieldset>

      <Button class="form-submit" type="submit" :label="editing ? t('notifications.save') : t('notifications.create')" :loading="saving" />
    </form>
  </Dialog>
</template>

<style scoped>
.panel {
  max-width: 72rem;
  margin-bottom: 1.25rem;
  padding: 1.25rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.panel-header :deep(button) {
  flex: none;
}

.panel-title {
  margin: 0 0 0.25rem;
  font-size: 1.0625rem;
  font-weight: 600;
}

.panel-error {
  max-width: 72rem;
  margin-bottom: 0.75rem;
}

.description,
.muted {
  margin: 0;
  color: var(--p-text-muted-color);
}

.empty {
  font-size: 0.875rem;
}

.table-wrapper {
  overflow-x: auto;
}

.list {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.list th,
.list td {
  padding: 0.375rem 0.5rem;
  border-bottom: 1px solid var(--p-content-border-color);
  text-align: left;
  vertical-align: middle;
}

.list th {
  color: var(--p-text-muted-color);
  font-weight: 500;
  white-space: nowrap;
}

.list tbody tr:last-child td {
  border-bottom: 0;
}

.disabled td:not(:first-child):not(:last-child) {
  opacity: 0.6;
}

.name {
  overflow-wrap: anywhere;
}

.this-browser {
  margin-left: 0.5rem;
}

.patterns {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}

.pattern {
  padding: 0.0625rem 0.3125rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  font-size: 0.8125rem;
}

.buttons {
  display: flex;
  justify-content: flex-end;
  gap: 0.125rem;
}

.rule-form,
.field,
.preview {
  display: flex;
  flex-direction: column;
}

.rule-form {
  gap: 1rem;
}

.field,
.preview {
  gap: 0.375rem;
}

.field small {
  color: var(--p-text-muted-color);
}

.monospace {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.preview {
  gap: 0.75rem;
  margin: 0;
  padding: 0.75rem 1rem 1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.preview legend {
  padding: 0 0.25rem;
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}

/* Close to what a system shows : the real look depends on it */
.notification {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  padding: 0.75rem;
  background: var(--p-content-hover-background);
  border-radius: 0.75rem;
}

.notification-icon {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.5rem;
}

.notification-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}

.form-submit {
  align-self: flex-end;
}

@media (max-width: 30rem) {
  .panel-header {
    flex-direction: column;
  }
}
</style>
