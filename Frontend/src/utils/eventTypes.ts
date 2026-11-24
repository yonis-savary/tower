import { EVENT_TYPE, HISTORY_ICONS, isSent } from './historyLine'
import type { WorkspaceSection } from '../stores/workspace'

/** Sent by the Discuss button of a Jira issue : the only event to the devices with no history line of its own */
const DISCUSS_EVENT = 'ai.discuss'

/** The events Tower sends to the devices by itself, from the history lines that reach them */
export const TOWER_EVENT_TYPES: string[] = [...Object.keys(HISTORY_ICONS).filter((type) => isSent(type) && type !== EVENT_TYPE), DISCUSS_EVENT]

// A workspace without a section never emits its events
const SECTION_OF_PREFIX: [string, WorkspaceSection][] = [
  ['task.', 'tasks'],
  ['sprint.', 'tasks'],
  ['pr.', 'merge_requests'],
  ['jira.issue.', 'jira'],
  ['ai.', 'jira'],
  ['agenda.', 'agenda'],
]

export function sectionOf(type: string): WorkspaceSection | undefined {
  return SECTION_OF_PREFIX.find(([prefix]) => type.startsWith(prefix))?.[1]
}

/** `*`, then `pr.*` and `pr.pipeline.*` for `pr.pipeline.failed` : every prefix of the given types */
export function patternsOf(types: string[]): string[] {
  const prefixes = types.flatMap((type) => type.split('.').slice(0, -1).map((_, index, parts) => `${parts.slice(0, index + 1).join('.')}.*`))

  return ['*', ...new Set(prefixes)].sort()
}

export type Token = { start: number; end: number; text: string }

const SEPARATOR = /[\s,]/

/** The word of a list (separated by spaces or commas) the caret is in, up to the caret */
export function tokenAt(value: string, caret: number): Token {
  let start = caret
  while (start > 0 && !SEPARATOR.test(value[start - 1]!)) start--

  let end = caret
  while (end < value.length && !SEPARATOR.test(value[end]!)) end++

  return { start, end, text: value.slice(start, caret) }
}

export function replaceToken(value: string, token: Token, replacement: string): string {
  return value.slice(0, token.start) + replacement + value.slice(token.end)
}

export function tokensOf(value: string): string[] {
  return value.split(/[\s,]+/).filter(Boolean)
}
