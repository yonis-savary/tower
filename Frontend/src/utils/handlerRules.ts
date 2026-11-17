import type { UpdateAccessKeyHandlersData } from '../client'

/** A handler rule as the API stores it, see App\Connector\HandlerRules */
export type HandlerRule = UpdateAccessKeyHandlersData['body']['handlers'][number]

export type Pair = { id: number; key: string; value: string }
export type ResponseMode = 'none' | 'text' | 'fields'

/** A rule while it is edited : every list item has an id, for the keys and the drag and drop */
export type RuleForm = {
  id: number
  pattern: string
  commands: { id: number; value: string }[]
  responseMode: ResponseMode
  responseText: string
  responseFields: Pair[]
  next: { id: number; type: string; payload: Pair[] }[]
}

// Mirrors App\Connector\HandlerRules and App\Services\AccessKeyService
export const LIMITS = { rules: 50, commands: 10, command: 1000, text: 4000, pairs: 20, key: 100, value: 1000, next: 10 }
const PATTERN = /^[a-z0-9_.*?-]{1,100}$/
const EVENT_TYPE = /^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/
const PLACEHOLDER = /\{[A-Za-z0-9_.]+\}/g

/** Known by the connector in every field */
export const EVENT_VARIABLES = ['{event}', '{payload}', '{payload.title}', '{id}', '{delivery}', '{workspace}']
/** Only once the commands have run : in the response and in the next events */
export const RESULT_VARIABLES = ['{stdout}', '{stderr}', '{exit_code}', '{status}']

let lastId = 0
export const nextId = () => ++lastId

const toPairs = (pairs: { key: string; value: string }[] = []): Pair[] => pairs.map(({ key, value }) => ({ id: nextId(), key, value }))
const fromPairs = (pairs: Pair[]) => pairs.map(({ key, value }) => ({ key, value }))

export function emptyRule(): RuleForm {
  return {
    id: nextId(),
    pattern: '',
    commands: [{ id: nextId(), value: '' }],
    responseMode: 'none',
    responseText: '',
    responseFields: [],
    next: [],
  }
}

export function toForm(rules: HandlerRule[]): RuleForm[] {
  return rules.map((rule) => ({
    id: nextId(),
    pattern: rule.pattern,
    commands: rule.run.map((value) => ({ id: nextId(), value })),
    responseMode: rule.response?.mode ?? 'none',
    responseText: rule.response?.mode === 'text' ? (rule.response.text ?? '') : '',
    responseFields: rule.response?.mode === 'fields' ? toPairs(rule.response.fields) : [],
    next: (rule.next ?? []).map((event) => ({ id: nextId(), type: event.type, payload: toPairs(event.payload) })),
  }))
}

export function fromForm(rules: RuleForm[]): HandlerRule[] {
  return rules.map((rule) => ({
    pattern: rule.pattern.trim(),
    run: rule.commands.map(({ value }) => value.trim()),
    response:
      rule.responseMode === 'text'
        ? { mode: 'text', text: rule.responseText }
        : rule.responseMode === 'fields'
          ? { mode: 'fields', fields: fromPairs(rule.responseFields) }
          : null,
    next: rule.next.map((event) => ({ type: event.type.trim(), payload: fromPairs(event.payload) })),
  }))
}

/**
 * Whether Python's shlex.split() accepts the command, as App\Connector\HandlerRules::isSplittable() :
 * the connector refuses a quote left open, or a lone backslash at the end.
 */
export function isSplittable(command: string): boolean {
  let quote: string | null = null

  for (let i = 0; i < command.length; i++) {
    const char = command[i]
    if (quote === "'") {
      if (char === "'") quote = null
      continue
    }
    if (char === '\\') {
      if (++i >= command.length) return false
      continue
    }
    if (char === '"' || (char === "'" && quote === null)) quote = quote === char ? null : (quote ?? char)
  }

  return quote === null
}

/** A type with placeholders ({event}.done) is checked as the connector would send it */
export function isEventType(type: string): boolean {
  return type.length <= 100 && EVENT_TYPE.test(type.replace(PLACEHOLDER, 'x'))
}

/** An i18n key under handlers.error, by field : `<rule id>.pattern`, `<command id>`, `<pair id>.key`… */
export type RuleErrors = Record<string, string>

function checkPairs(pairs: Pair[], errors: RuleErrors) {
  const seen = new Set<string>()
  for (const pair of pairs) {
    const key = pair.key.trim()
    if (!key || key.length > LIMITS.key) errors[`${pair.id}.key`] = 'key'
    else if (seen.has(key)) errors[`${pair.id}.key`] = 'keyTwice'
    seen.add(key)
    if (pair.value.length > LIMITS.value) errors[`${pair.id}.value`] = 'value'
  }
}

export function validate(rules: RuleForm[]): RuleErrors {
  const errors: RuleErrors = {}
  const patterns = new Set<string>()

  for (const rule of rules) {
    const pattern = rule.pattern.trim()
    if (!PATTERN.test(pattern)) errors[`${rule.id}.pattern`] = 'pattern'
    else if (patterns.has(pattern)) errors[`${rule.id}.pattern`] = 'patternTwice'
    patterns.add(pattern)

    for (const command of rule.commands) {
      const value = command.value.trim()
      if (!value || value.length > LIMITS.command) errors[command.id] = 'command'
      else if (!isSplittable(value)) errors[command.id] = 'quotes'
    }

    if (rule.responseMode === 'text' && !rule.responseText.trim()) errors[`${rule.id}.text`] = 'text'
    if (rule.responseMode === 'fields') {
      if (!rule.responseFields.length) errors[`${rule.id}.fields`] = 'fields'
      checkPairs(rule.responseFields, errors)
    }

    for (const event of rule.next) {
      if (!isEventType(event.type.trim())) errors[`${event.id}.type`] = 'type'
      checkPairs(event.payload, errors)
    }
  }

  return errors
}

/** The patterns no listened pattern covers : the devices would never receive these events */
export function unlistened(rules: RuleForm[], listen: string[]): string[] {
  const covers = (glob: string, pattern: string) => {
    const regex = new RegExp(`^${glob.replace(/[.+^${}()|[\]\\]/g, '\\$&').replaceAll('*', '.*').replaceAll('?', '.')}$`)
    return regex.test(pattern)
  }

  return rules.map(({ pattern }) => pattern.trim()).filter((pattern) => pattern && !listen.some((glob) => covers(glob, pattern)))
}

/** Once the errors are rendered : the first one may be far below, in a long list of handlers */
export function scrollToFirstError() {
  requestAnimationFrame(() => document.querySelector('.p-dialog .p-invalid')?.scrollIntoView({ block: 'center', behavior: 'smooth' }))
}
