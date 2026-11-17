<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { VueDraggable } from 'vue-draggable-plus'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import SelectButton from 'primevue/selectbutton'
import Textarea from 'primevue/textarea'
import EventTypeInput from './EventTypeInput.vue'
import HandlerPairs from './HandlerPairs.vue'
import { EVENT_VARIABLES, LIMITS, RESULT_VARIABLES, emptyRule, nextId, unlistened, type RuleErrors, type RuleForm } from '../utils/handlerRules'

/** The handler rules of a configuration : what each event runs, answers, and triggers next */
const rules = defineModel<RuleForm[]>({ required: true })
const props = defineProps<{ errors: RuleErrors; listen: string[] }>()

const { t } = useI18n()

const responseModes = computed(() => [
  { value: 'none', label: t('handlers.responseNone') },
  { value: 'text', label: t('handlers.responseText') },
  { value: 'fields', label: t('handlers.responseFields') },
])
const notListened = computed(() => unlistened(rules.value, props.listen))

// The field a variable chip writes into : the last one focused in the editor
let focused: HTMLInputElement | HTMLTextAreaElement | null = null

function track(event: FocusEvent) {
  const target = event.target
  if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) focused = target
}

function insert(variable: string) {
  if (!focused?.isConnected) return

  const start = focused.selectionStart ?? focused.value.length
  focused.setRangeText(variable, start, focused.selectionEnd ?? start, 'end')
  // v-model listens to input events
  focused.dispatchEvent(new Event('input', { bubbles: true }))
  focused.focus()
}

function addRule() {
  rules.value.push(emptyRule())
}

function removeRule(id: number) {
  rules.value = rules.value.filter((rule) => rule.id !== id)
}

function addCommand(rule: RuleForm) {
  rule.commands.push({ id: nextId(), value: '' })
}

function removeCommand(rule: RuleForm, id: number) {
  rule.commands = rule.commands.filter((command) => command.id !== id)
}

function addNext(rule: RuleForm) {
  rule.next.push({ id: nextId(), type: '', payload: [] })
}

function removeNext(rule: RuleForm, id: number) {
  rule.next = rule.next.filter((event) => event.id !== id)
}

function error(key: string | number): string | null {
  const code = props.errors[key]
  return code ? t(`handlers.error.${code}`) : null
}
</script>

<template>
  <div class="editor" @focusin="track">
    <div class="variables">
      <span class="variables-title">{{ t('handlers.variablesTitle') }}</span>
      <div class="chips">
        <button v-for="variable in EVENT_VARIABLES" :key="variable" type="button" class="chip" :title="t('handlers.variableInsert')" @mousedown.prevent @click="insert(variable)">
          {{ variable }}
        </button>
        <span class="chips-separator" aria-hidden="true" />
        <button
          v-for="variable in RESULT_VARIABLES"
          :key="variable"
          type="button"
          class="chip chip-result"
          :title="t('handlers.variableResult')"
          @mousedown.prevent
          @click="insert(variable)"
        >
          {{ variable }}
        </button>
      </div>
      <small>{{ t('handlers.variablesHint') }}</small>
    </div>

    <Message v-if="notListened.length" severity="warn" :closable="false" class="listen-warning">
      {{ t('handlers.notListened', { patterns: notListened.join(', ') }) }}
    </Message>

    <VueDraggable v-model="rules" class="rules" handle=".rule-handle" :animation="150" ghost-class="rule-ghost">
      <section v-for="rule in rules" :key="rule.id" class="rule">
        <header class="rule-header">
          <i class="pi pi-bars rule-handle" :title="t('handlers.move')" aria-hidden="true" />
          <label class="rule-pattern">
            <span>{{ t('handlers.patternLabel') }}</span>
            <EventTypeInput v-model="rule.pattern" :aria-label="t('handlers.patternLabel')" :placeholder="t('handlers.patternPlaceholder')" size="small" suggest-patterns :maxlength="100" />
          </label>
          <Button icon="pi pi-trash" severity="danger" text rounded size="small" :aria-label="t('handlers.removeRule')" :title="t('handlers.removeRule')" @click="removeRule(rule.id)" />
        </header>
        <small v-if="error(`${rule.id}.pattern`)" class="field-error">{{ error(`${rule.id}.pattern`) }}</small>

        <div class="block">
          <span class="block-title">{{ t('handlers.commandsTitle') }}</span>
          <small class="block-hint">{{ t('handlers.commandsHint') }}</small>
          <VueDraggable v-model="rule.commands" class="commands" handle=".command-handle" :animation="150">
            <div v-for="(command, index) in rule.commands" :key="command.id" class="command">
              <span class="command-index command-handle" :title="t('handlers.move')">{{ index + 1 }}</span>
              <InputText
                v-model="command.value"
                :placeholder="t('handlers.commandPlaceholder', { variable: '{payload.title}' })"
                :aria-label="t('handlers.commandLabel', { index: index + 1 })"
                :invalid="!!error(command.id)"
                :maxlength="LIMITS.command"
                size="small"
                class="mono"
                fluid
              />
              <Button
                v-if="rule.commands.length > 1"
                icon="pi pi-times"
                severity="secondary"
                text
                rounded
                size="small"
                :aria-label="t('handlers.remove')"
                @click="removeCommand(rule, command.id)"
              />
              <small v-if="error(command.id)" class="field-error">{{ error(command.id) }}</small>
            </div>
          </VueDraggable>
          <Button v-if="rule.commands.length < LIMITS.commands" :label="t('handlers.addCommand')" icon="pi pi-plus" severity="secondary" text size="small" class="add" @click="addCommand(rule)" />
        </div>

        <div class="block">
          <span class="block-title">{{ t('handlers.responseTitle') }}</span>
          <SelectButton v-model="rule.responseMode" :options="responseModes" option-label="label" option-value="value" :allow-empty="false" size="small" :aria-label="t('handlers.responseTitle')" />
          <small v-if="rule.responseMode === 'none'" class="block-hint">{{ t('handlers.responseNoneHint') }}</small>
          <template v-else-if="rule.responseMode === 'text'">
            <Textarea
              v-model="rule.responseText"
              :placeholder="t('handlers.responseTextPlaceholder', { variable: '{stdout}' })"
              :aria-label="t('handlers.responseText')"
              :invalid="!!error(`${rule.id}.text`)"
              :maxlength="LIMITS.text"
              rows="2"
              auto-resize
              class="mono"
              fluid
            />
            <small v-if="error(`${rule.id}.text`)" class="field-error">{{ error(`${rule.id}.text`) }}</small>
          </template>
          <template v-else>
            <small class="block-hint">{{ t('handlers.responseFieldsHint', { variable: '{exit_code}' }) }}</small>
            <HandlerPairs v-model="rule.responseFields" :errors="errors" :add-label="t('handlers.addField')" />
            <small v-if="error(`${rule.id}.fields`)" class="field-error">{{ error(`${rule.id}.fields`) }}</small>
          </template>
        </div>

        <div class="block">
          <span class="block-title">{{ t('handlers.nextTitle') }}</span>
          <small class="block-hint">{{ t('handlers.nextHint', { example: '{event}.done' }) }}</small>
          <div v-for="event in rule.next" :key="event.id" class="next-event">
            <div class="next-type">
              <EventTypeInput v-model="event.type" :aria-label="t('handlers.nextType')" :placeholder="t('handlers.nextType')" size="small" :maxlength="100" />
              <Button icon="pi pi-times" severity="secondary" text rounded size="small" :aria-label="t('handlers.remove')" @click="removeNext(rule, event.id)" />
            </div>
            <small v-if="error(`${event.id}.type`)" class="field-error">{{ error(`${event.id}.type`) }}</small>
            <HandlerPairs v-model="event.payload" :errors="errors" :add-label="t('handlers.addPayloadField')" />
          </div>
          <Button v-if="rule.next.length < LIMITS.next" :label="t('handlers.addNext')" icon="pi pi-plus" severity="secondary" text size="small" class="add" @click="addNext(rule)" />
        </div>
      </section>
    </VueDraggable>

    <p v-if="!rules.length" class="empty">{{ t('handlers.empty') }}</p>
    <Button v-if="rules.length < LIMITS.rules" :label="t('handlers.addRule')" icon="pi pi-plus" severity="secondary" outlined class="add-rule" @click="addRule" />
  </div>
</template>

<style scoped>
.editor {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.variables {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  padding: 0.625rem 0.75rem;
  background: var(--p-content-hover-background);
  border-radius: var(--p-content-border-radius);
}

.variables small,
.block-hint,
.empty {
  color: var(--p-text-muted-color);
}

.variables-title,
.block-title {
  font-size: 0.875rem;
  font-weight: 600;
}

.chips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem;
}

.chip {
  padding: 0.125rem 0.5rem;
  color: var(--p-primary-color);
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: 1rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.75rem;
  cursor: pointer;
}

.chip:hover {
  border-color: var(--p-primary-color);
}

.chip-result {
  color: light-dark(var(--p-orange-700), var(--p-orange-300));
}

.chip-result:hover {
  border-color: currentColor;
}

.chips-separator {
  width: 1px;
  height: 1rem;
  margin: 0 0.25rem;
  background: var(--p-content-border-color);
}

.listen-warning,
.empty {
  margin: 0;
}

.rules {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.rule {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.75rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.rule-ghost {
  opacity: 0.4;
}

.rule-header {
  display: flex;
  align-items: flex-end;
  gap: 0.5rem;
}

.rule-handle,
.command-handle {
  cursor: grab;
  touch-action: none;
}

.rule-handle {
  padding: 0.5rem 0.25rem;
  color: var(--p-text-muted-color);
}

.rule-pattern {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
  font-size: 0.875rem;
  font-weight: 600;
}

.rule-pattern :deep(.p-autocomplete),
.next-type :deep(.p-autocomplete) {
  width: 100%;
}

.block {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  padding-top: 0.625rem;
  border-top: 1px dashed var(--p-content-border-color);
}

.block :deep(.p-selectbutton) {
  align-self: flex-start;
}

.commands {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.command {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.375rem;
}

.command :deep(input) {
  flex: 1 1 12rem;
  width: auto;
}

.command-index {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  color: var(--p-text-muted-color);
  background: var(--p-content-hover-background);
  border-radius: 50%;
  font-size: 0.75rem;
}

.mono,
.mono :deep(input) {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.next-event {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  padding: 0.5rem 0 0.5rem 0.75rem;
  border-left: 2px solid var(--p-content-border-color);
}

.next-type {
  display: flex;
  align-items: center;
  gap: 0.375rem;
}

.next-type > :first-child {
  flex: 1;
  min-width: 0;
}

.field-error {
  flex-basis: 100%;
  color: var(--p-red-500);
}

.add {
  align-self: flex-start;
}

.add-rule {
  align-self: flex-start;
}
</style>
