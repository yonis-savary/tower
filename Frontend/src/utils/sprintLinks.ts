import type { SprintLink } from '../stores/tasks'

export function hostOf(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, '')
  } catch {
    return url
  }
}

export function linkLabel(link: SprintLink): string {
  return link.label || hostOf(link.url)
}

export function sortedLinks(links: SprintLink[]): SprintLink[] {
  return [...links].sort((a, b) => linkLabel(a).localeCompare(linkLabel(b), undefined, { sensitivity: 'base', numeric: true }))
}

/** The same site always gets the same color : the icon stays recognizable without its favicon */
export function hueOf(url: string): number {
  return [...hostOf(url)].reduce((hash, character) => (hash * 31 + character.charCodeAt(0)) % 360, 7)
}

/** A typed address without scheme is a web page */
export function normalizedUrl(url: string): string {
  const trimmed = url.trim()
  return /^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`
}
