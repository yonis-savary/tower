export type Frequency = 'minute' | 'minutes' | 'hourly' | 'hours' | 'daily' | 'weekly' | 'monthly'

export const FREQUENCIES: Frequency[] = ['minute', 'minutes', 'hourly', 'hours', 'daily', 'weekly', 'monthly']

// Cron counts the hours from midnight : only a divisor of 24 keeps the same gap across the night
export const HOUR_STEPS = [2, 3, 4, 6, 8, 12]

// Sunday is 0, as in cron and Date.getDay()
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 0]

export type Recurrence = {
  frequency: Frequency
  step: number
  hourStep: number
  minute: number
  hour: number
  weekdays: number[]
  monthDay: number
}

export const DEFAULT_RECURRENCE: Recurrence = { frequency: 'daily', step: 15, hourStep: 2, minute: 0, hour: 9, weekdays: [1], monthDay: 1 }

const toCronByFrequency: Record<Frequency, (recurrence: Recurrence) => string> = {
  minute: () => '* * * * *',
  minutes: ({ step }) => `*/${step} * * * *`,
  hourly: ({ minute }) => `${minute} * * * *`,
  hours: ({ hourStep }) => `0 */${hourStep} * * *`,
  daily: ({ minute, hour }) => `${minute} ${hour} * * *`,
  weekly: ({ minute, hour, weekdays }) => `${minute} ${hour} * * ${[...weekdays].sort().join(',')}`,
  monthly: ({ minute, hour, monthDay }) => `${minute} ${hour} ${monthDay} * *`,
}

export function toCron(recurrence: Recurrence): string {
  return toCronByFrequency[recurrence.frequency](recurrence)
}

// Each shape is one that toCron() writes : any other cron, set through the API, is not recognized
const fromCronPatterns: [RegExp, (values: string[]) => Partial<Recurrence>][] = [
  [/^\* \* \* \* \*$/, () => ({ frequency: 'minute' })],
  [/^\*\/(\d+) \* \* \* \*$/, ([step]) => ({ frequency: 'minutes', step: Number(step) })],
  [/^(\d+) \* \* \* \*$/, ([minute]) => ({ frequency: 'hourly', minute: Number(minute) })],
  [new RegExp(`^0 \\*/(${HOUR_STEPS.join('|')}) \\* \\* \\*$`), ([hourStep]) => ({ frequency: 'hours', hourStep: Number(hourStep) })],
  [/^(\d+) (\d+) \* \* \*$/, ([minute, hour]) => ({ frequency: 'daily', minute: Number(minute), hour: Number(hour) })],
  [
    /^(\d+) (\d+) \* \* ([0-7](?:,[0-7])*)$/,
    // Cron also names sunday 7
    ([minute, hour, weekdays]) => ({ frequency: 'weekly', minute: Number(minute), hour: Number(hour), weekdays: [...new Set(weekdays!.split(',').map((day) => Number(day) % 7))] }),
  ],
  [/^(\d+) (\d+) (\d+) \* \*$/, ([minute, hour, monthDay]) => ({ frequency: 'monthly', minute: Number(minute), hour: Number(hour), monthDay: Number(monthDay) })],
]

export function fromCron(cron: string): Recurrence | null {
  for (const [pattern, read] of fromCronPatterns) {
    const match = pattern.exec(cron)
    if (match) return { ...DEFAULT_RECURRENCE, ...read(match.slice(1)) }
  }
  return null
}

export function timeOf({ hour, minute }: Pick<Recurrence, 'hour' | 'minute'>): string {
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`
}

// The server reads it in its own timezone
export function toServerDateTime(date: Date): string {
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:00`
}
