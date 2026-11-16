/** The public VAPID key, as the browser wants it */
export function decodeBase64Url(value: string): Uint8Array<ArrayBuffer> {
  const base64 = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)

  return Uint8Array.from(raw, (char) => char.charCodeAt(0))
}

export function sameKey(left: ArrayBuffer | null | undefined, right: Uint8Array): boolean {
  if (!left || left.byteLength !== right.byteLength) return false
  const bytes = new Uint8Array(left)

  return bytes.every((byte, index) => byte === right[index])
}

/** iPhone and iPad : Web Push only works in the application added to the home screen */
export function isIos(): boolean {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

export function isStandalone(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true
}

/** "Firefox · Linux" : enough to tell its browsers apart */
export function describeUserAgent(userAgent: string | null): string | null {
  if (!userAgent) return null

  const browser = /Edg\//.test(userAgent) ? 'Edge'
    : /OPR\//.test(userAgent) ? 'Opera'
    : /Firefox\/|FxiOS/.test(userAgent) ? 'Firefox'
    : /Chrome\/|CriOS/.test(userAgent) ? 'Chrome'
    : /Safari\//.test(userAgent) ? 'Safari'
    : null
  const system = /Android/.test(userAgent) ? 'Android'
    : /iPhone|iPad|iPod/.test(userAgent) ? 'iOS'
    : /Mac OS X/.test(userAgent) ? 'macOS'
    : /Windows/.test(userAgent) ? 'Windows'
    : /CrOS/.test(userAgent) ? 'ChromeOS'
    : /Linux/.test(userAgent) ? 'Linux'
    : null

  return [browser, system].filter(Boolean).join(' · ') || userAgent.slice(0, 60)
}
