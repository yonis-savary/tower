type Translate = (key: string) => string

/** The "before → after" of a task history line, empty when the line has none */
export function taskChange(t: Translate, type: string, details: Record<string, any>): string {
  if (type === 'task.status_changed') return `${t(`tasks.status.${details.from}`)} → ${t(`tasks.status.${details.to}`)}`
  if (type === 'task.sprint_changed') return `${details.from ?? t('tasks.backlog')} → ${details.to ?? t('tasks.backlog')}`
  return ''
}
