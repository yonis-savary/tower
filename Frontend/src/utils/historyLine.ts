import { PULL_REQUEST_ICON } from '../components/AppIcon.vue'
import { taskChange } from './taskChange'

/** What a history line needs from vue-i18n */
export type HistoryI18n = {
  t: (key: string, params?: Record<string, unknown>) => string
  te: (key: string) => boolean
}

export type Details = Record<string, any>

/** An event launched by hand, by a quick action, by a webhook or by a schedule */
export const EVENT_TYPE = 'event.triggered'

export const HISTORY_ICONS: Record<string, string> = {
  'device.connected': 'pi pi-link',
  'device.disconnected': 'pi pi-times-circle',
  [EVENT_TYPE]: 'pi pi-bolt',
  'key.created': 'pi pi-key',
  'key.revoked': 'pi pi-ban',
  'key.handlers_updated': 'pi pi-cog',
  'key.secret_rotated': 'pi pi-refresh',
  'token.created': 'pi pi-sparkles',
  'token.revoked': 'pi pi-ban',
  'webhook.created': 'pi pi-globe',
  'webhook.enabled': 'pi pi-play',
  'webhook.disabled': 'pi pi-pause',
  'webhook.deleted': 'pi pi-trash',
  'webhook.secret_regenerated': 'pi pi-key',
  'schedule.created': 'pi pi-clock',
  'schedule.enabled': 'pi pi-play',
  'schedule.disabled': 'pi pi-pause',
  'schedule.deleted': 'pi pi-trash',
  'pr.opened': PULL_REQUEST_ICON,
  'pr.merged': 'pi pi-check-circle',
  'pr.declined': 'pi pi-times-circle',
  'pr.pipeline.failed': 'pi pi-times',
  'pr.pipeline.succeeded': 'pi pi-check',
  'pr.review.approved': 'pi pi-thumbs-up',
  'pr.review.changes_requested': 'pi pi-thumbs-down',
  'pr.commented': 'pi pi-comment',
  'pr.pushed': 'pi pi-upload',
  'pr.ready': 'pi pi-eye',
  'pr.retargeted': 'pi pi-directions',
  'jira.issue.assigned': 'pi pi-objects-column',
  'jira.issue.done': 'pi pi-check-square',
  'jira.issue.unassigned': 'pi pi-user-minus',
  'jira.issue.status_changed': 'pi pi-arrow-right',
  'jira.issue.sprint_changed': 'pi pi-calendar',
  'jira.issue.priority_changed': 'pi pi-sort-amount-up',
  'jira.issue.updated': 'pi pi-pencil',
  'task.created': 'pi pi-plus',
  'task.updated': 'pi pi-pencil',
  'task.status_changed': 'pi pi-arrow-right',
  'task.sprint_changed': 'pi pi-calendar',
  'sprint.created': 'pi pi-calendar-plus',
  'sprint.opened': 'pi pi-play',
  'sprint.closed': 'pi pi-flag',
  'sprint.link_added': 'pi pi-link',
  'sprint.link_updated': 'pi pi-link',
  'sprint.link_removed': 'pi pi-link',
}

export const DELIVERY_SEVERITY: Record<string, string> = { succeeded: 'success', received: 'success', failed: 'danger', running: 'info', pending: 'warn' }

export function parseDetails(details: string): Details {
  try {
    return JSON.parse(details)
  } catch {
    return {}
  }
}

/** The lines sent to the devices : the others have no delivery */
export function isSent(type: string): boolean {
  return [EVENT_TYPE, 'task.', 'sprint.', 'pr.', 'jira.issue.'].some((prefix) => type.startsWith(prefix))
}

export function historyLabel({ t, te }: HistoryI18n, type: string): string {
  // A dot in a message key would read as one more level
  const key = `history.type.${type.replaceAll('.', '_')}`
  return te(key) ? t(key) : type
}

export function historyDetail(i18n: HistoryI18n, type: string, parsed: Details): string {
  const detail = describe(i18n, type, parsed)
  return parsed.token ? [detail, i18n.t('history.viaToken', { name: parsed.token })].filter(Boolean).join(' · ') : detail
}

function describe({ t, te }: HistoryI18n, type: string, parsed: Details): string {
  if (type.startsWith('pr.')) return `${parsed.title} · ${parsed.repository}${integrationChange(type, parsed)}`
  if (type.startsWith('jira.issue.')) return `${parsed.key} · ${parsed.summary}${integrationChange(type, parsed)}`

  if (type.startsWith('sprint.link_')) return `${parsed.name} · ${parsed.link}`

  if (type.startsWith('task.')) {
    const change = taskChange(t, type, parsed)
    return change ? `${parsed.title} · ${change}` : parsed.title
  }

  switch (type) {
    case EVENT_TYPE:
      if (parsed.webhook) return `${parsed.event} · ${t('history.viaWebhook', { name: parsed.webhook })}`
      if (parsed.schedule) return `${parsed.event} · ${t('history.viaSchedule', { name: parsed.schedule })}`
      if (parsed.answering) return `${parsed.event} · ${t('history.viaDevice', { name: parsed.device ?? t('devices.unknownHost'), line: parsed.answering })}`
      return parsed.event
    case 'device.connected':
      return `${parsed.hostname ?? t('devices.unknownHost')} · ${parsed.access_key}`
    case 'device.disconnected': {
      const reason = `history.reason.${parsed.reason}`
      return `${parsed.hostname ?? t('devices.unknownHost')} · ${te(reason) ? t(reason) : parsed.reason}`
    }
    default:
      return parsed.name ?? ''
  }
}

const transition = (parsed: Details) => ` · ${parsed.from ?? '∅'} → ${parsed.to ?? '∅'}`
const outcome = (parsed: Details) => (parsed.state ?? parsed.status ? ` · ${parsed.state ?? parsed.status}` : '')

const integrationChanges: Record<string, (parsed: Details) => string> = {
  'pr.declined': outcome,
  'pr.review.approved': (parsed) => ` · ${parsed.reviewer}`,
  'pr.review.changes_requested': (parsed) => ` · ${parsed.reviewer}`,
  'pr.commented': (parsed) => ` · +${parsed.count}`,
  'pr.pushed': (parsed) => ` · ${parsed.branch} ${String(parsed.to).slice(0, 7)}`,
  'pr.retargeted': transition,
  'jira.issue.done': outcome,
  'jira.issue.unassigned': outcome,
  'jira.issue.status_changed': transition,
  'jira.issue.sprint_changed': transition,
  'jira.issue.priority_changed': transition,
}

function integrationChange(type: string, parsed: Details): string {
  return integrationChanges[type]?.(parsed) ?? ''
}
