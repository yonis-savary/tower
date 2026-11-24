import type { EventInput } from '@fullcalendar/core'
import type { ListAgendaItemsResponse, ListAgendaSchedulesResponse } from '../client'

export type AgendaItem = ListAgendaItemsResponse[number]
// The document reads this answer from an example, which cannot tell that every key is always given
export type ScheduleOccurrence = Required<ListAgendaSchedulesResponse[number]>

export type AgendaDraft = { start: Date; end: Date; allDay: boolean }

const SCHEDULE_DURATION_MINUTES = 15

export const AGENDA_COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6', '#6366f1', '#a855f7', '#ec4899']

export function addDays(date: Date, days: number): Date {
  const moved = new Date(date)
  moved.setDate(moved.getDate() + days)
  return moved
}

export function dayOf(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

// `end` is exclusive, as FullCalendar gives it. A timed item travels as an instant, an all-day one as its days, whatever the timezones
export function agendaBody({ start, end, allDay }: AgendaDraft): { starts_at: string; ends_at: string; all_day: boolean } {
  return allDay
    ? { starts_at: `${dayOf(start)} 00:00:00`, ends_at: `${dayOf(end)} 00:00:00`, all_day: true }
    : { starts_at: start.toISOString(), ends_at: end.toISOString(), all_day: false }
}

// The API replaces the whole item : a field left out here would be erased by a move
export function itemBody(item: AgendaItem, dates: AgendaDraft) {
  return {
    ...agendaBody(dates),
    title: item.title,
    description: item.description,
    link: item.link,
    color: item.color,
    participants: item.participants.map(({ id }) => id),
  }
}

export function itemEvent(item: AgendaItem, color: string): EventInput {
  return {
    id: `item:${item.id}`,
    title: item.title,
    // An all-day item keeps its days whatever the timezone of the browser
    start: item.all_day ? item.starts_at.slice(0, 10) : item.starts_at,
    end: item.all_day ? item.ends_at.slice(0, 10) : item.ends_at,
    allDay: item.all_day,
    backgroundColor: color,
    borderColor: color,
    extendedProps: { item },
  }
}

export function scheduleEvent(occurrence: ScheduleOccurrence, color: string, frequentTitle: string): EventInput {
  const start = new Date(occurrence.at)
  return {
    id: `schedule:${occurrence.schedule}:${occurrence.at}`,
    title: occurrence.frequent ? frequentTitle : occurrence.name,
    start: occurrence.frequent ? occurrence.at.slice(0, 10) : start,
    end: occurrence.frequent ? undefined : new Date(start.getTime() + SCHEDULE_DURATION_MINUTES * 60_000),
    allDay: occurrence.frequent,
    editable: false,
    textColor: color,
    borderColor: color,
    backgroundColor: 'transparent',
    classNames: ['schedule-occurrence'],
    extendedProps: { occurrence },
  }
}
