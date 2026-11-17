export type Point = { x: number; y: number }

export type FlowChipKind = 'interface' | 'jira' | 'bitbucket' | 'webhook' | 'schedule' | 'device' | 'rule'

export type FlowChip = {
  key: string
  kind: FlowChipKind
  label: string
  detail: string
  enabled: boolean
}

export type PlacedChip = FlowChip & { x: number; y: number; trace: Point[] }

export type FlowGroup = { key: string; label: string; chips: FlowChip[]; empty: string }

export type FlowLayout = {
  width: number
  height: number
  tower: { x: number; y: number; width: number; height: number }
  sources: PlacedChip[]
  outputs: PlacedChip[]
  headers: { label: string; x: number; y: number }[]
  placeholders: { label: string; x: number; y: number }[]
}

export const VIEW_WIDTH = 1200
export const CHIP_WIDTH = 200
export const CHIP_HEIGHT = 52
const ROW = CHIP_HEIGHT + 18
const MARGIN = 28
const HEADER = 30
const TOWER_WIDTH = 200
const TOWER_MIN_HEIGHT = 180
const PIN_SPACING = 26
const BEND_START = 36
const BEND_STEP = 10
const BEVEL = 22

type Column = { chips: { chip: FlowChip; y: number }[]; headers: FlowLayout['headers']; placeholders: FlowLayout['placeholders'] }

export function layoutFlow(sourceGroups: FlowGroup[], outputGroups: FlowGroup[]): FlowLayout {
  const sourcesHeight = columnHeight(sourceGroups)
  const outputsHeight = columnHeight(outputGroups)
  const pins = Math.max(rowsOf(sourceGroups), rowsOf(outputGroups))
  const towerHeight = Math.max(TOWER_MIN_HEIGHT, (pins + 1) * PIN_SPACING)
  const height = Math.max(sourcesHeight, outputsHeight, towerHeight) + 2 * MARGIN

  const tower = { x: (VIEW_WIDTH - TOWER_WIDTH) / 2, y: (height - towerHeight) / 2, width: TOWER_WIDTH, height: towerHeight }
  const towerPin = (index: number, count: number) => tower.y + ((index + 1) * towerHeight) / (count + 1)

  const sourceColumn = placeColumn(sourceGroups, MARGIN, (height - sourcesHeight) / 2)
  const sourceEnds = sourceColumn.chips.map(({ y }, index) => ({
    start: { x: MARGIN + CHIP_WIDTH, y: y + CHIP_HEIGHT / 2 },
    end: { x: tower.x, y: towerPin(index, sourceColumn.chips.length) },
  }))
  const sourceRanks = bendRanks(sourceEnds)
  const sources = sourceColumn.chips.map(({ chip, y }, index) => {
    const { start, end } = sourceEnds[index]!
    return { ...chip, x: MARGIN, y, trace: pcbTrace(start, end, start.x + BEND_START + sourceRanks[index]! * BEND_STEP) }
  })

  const outputsX = VIEW_WIDTH - MARGIN - CHIP_WIDTH
  const outputColumn = placeColumn(outputGroups, outputsX, (height - outputsHeight) / 2)
  const outputEnds = outputColumn.chips.map(({ y }, index) => ({
    start: { x: tower.x + tower.width, y: towerPin(index, outputColumn.chips.length) },
    end: { x: outputsX, y: y + CHIP_HEIGHT / 2 },
  }))
  const outputRanks = bendRanks(outputEnds)
  const outputs = outputColumn.chips.map(({ chip, y }, index) => {
    const { start, end } = outputEnds[index]!
    const sameDirection = outputEnds.filter((other) => Math.sign(other.end.y - other.start.y) === Math.sign(end.y - start.y)).length
    return { ...chip, x: outputsX, y, trace: pcbTrace(start, end, start.x + BEND_START + (sameDirection - 1 - outputRanks[index]!) * BEND_STEP) }
  })

  return {
    width: VIEW_WIDTH,
    height,
    tower,
    sources,
    outputs,
    headers: [...sourceColumn.headers, ...outputColumn.headers],
    placeholders: [...sourceColumn.placeholders, ...outputColumn.placeholders],
  }
}

function rowsOf(groups: FlowGroup[]): number {
  return groups.reduce((rows, group) => rows + Math.max(group.chips.length, 1), 0)
}

function columnHeight(groups: FlowGroup[]): number {
  return groups.length * HEADER + rowsOf(groups) * ROW
}

function placeColumn(groups: FlowGroup[], x: number, top: number): Column {
  const column: Column = { chips: [], headers: [], placeholders: [] }
  let cursor = top

  for (const group of groups) {
    column.headers.push({ label: group.label, x, y: cursor + HEADER - 10 })
    cursor += HEADER

    if (!group.chips.length) {
      column.placeholders.push({ label: group.empty, x, y: cursor + (ROW - CHIP_HEIGHT) / 2 })
      cursor += ROW
      continue
    }

    for (const chip of group.chips) {
      column.chips.push({ chip, y: cursor + (ROW - CHIP_HEIGHT) / 2 })
      cursor += ROW
    }
  }

  return column
}

/**
 * Traces never cross when the ones that climb or drop the most turn closest to Tower : the rank of
 * each trace among those going the same way, by how far it climbs or drops.
 */
function bendRanks(ends: { start: Point; end: Point }[]): number[] {
  const rises = ends.map(({ start, end }) => end.y - start.y)
  return rises.map((rise, index) =>
    rises.filter((other, otherIndex) => Math.sign(other) === Math.sign(rise) && (Math.abs(other) < Math.abs(rise) || (Math.abs(other) === Math.abs(rise) && otherIndex < index))).length,
  )
}

/** Horizontal, a 45° bevel, vertical, a bevel, horizontal : the way a printed circuit trace turns */
export function pcbTrace(start: Point, end: Point, bendX: number): Point[] {
  const rise = end.y - start.y
  if (Math.abs(rise) < 1) return [start, end]

  const direction = Math.sign(rise)
  const bevel = Math.min(BEVEL, Math.abs(rise) / 2)

  return [
    start,
    { x: bendX, y: start.y },
    { x: bendX + bevel, y: start.y + direction * bevel },
    { x: bendX + bevel, y: end.y - direction * bevel },
    { x: bendX + 2 * bevel, y: end.y },
    end,
  ]
}

export function pathOf(points: Point[]): string {
  return points.map(({ x, y }, index) => `${index ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
}

/** fnmatch, as the server matches a rule : `*` any run of characters, `?` one character */
export function matchesPattern(pattern: string, value: string): boolean {
  const expression = pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.')
  return new RegExp(`^${expression}$`).test(value)
}
