import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { getWorkspaceSocket } from '../client'

export type SocketStatus = 'connecting' | 'connected' | 'disconnected'

export type ConnectedDevice = {
  uuid: string
  hostname: string | null
  type: 'desktop' | 'laptop' | 'mobile' | 'unknown'
  user_agent: string | null
  access_key: string
  connected_at: string
}

/** Sent when some data of the workspace changed : it carries no data, the API gives it */
export type WorkspaceSignal =
  | { type: 'history.created'; history_type: string }
  | { type: 'delivery.updated'; history: number }
  | { type: 'webhook.refused' }

const SIGNALS: string[] = ['history.created', 'delivery.updated', 'webhook.refused']
const MAX_RETRY_DELAY_MS = 30_000

export const useDeviceSocketStore = defineStore('deviceSocket', () => {
  const status = ref<SocketStatus>('disconnected')
  const devices = ref<ConnectedDevice[]>([])
  const activeDevices = computed(() => devices.value.length)

  let socket: WebSocket | null = null
  let retryTimer: ReturnType<typeof setTimeout> | undefined
  let retries = 0
  // Bumped on each connect/disconnect : what an older attempt still has running must be ignored
  let generation = 0
  const listeners = new Set<(signal: WorkspaceSignal) => void>()

  /** @returns What stops listening */
  function onSignal(listener: (signal: WorkspaceSignal) => void): () => void {
    listeners.add(listener)
    return () => listeners.delete(listener)
  }

  function teardown(): void {
    generation++
    clearTimeout(retryTimer)
    socket?.close()
    socket = null
    devices.value = []
  }

  function scheduleRetry(attempt: number): void {
    status.value = 'disconnected'
    retryTimer = setTimeout(() => open(attempt), Math.min(1000 * 2 ** retries++, MAX_RETRY_DELAY_MS))
  }

  async function open(attempt: number): Promise<void> {
    status.value = 'connecting'

    // Asked again on each attempt : the URL holds a token valid for a minute
    const { data } = await getWorkspaceSocket()
    if (attempt !== generation) return
    if (!data?.url) return scheduleRetry(attempt)

    const opened = new WebSocket(data.url)
    socket = opened

    opened.onmessage = ({ data: frame }) => {
      if (attempt !== generation) return

      const message = JSON.parse(frame)
      if (SIGNALS.includes(message.type)) {
        listeners.forEach((listener) => listener(message))
        return
      }
      if (message.type !== 'devices.list') return

      devices.value = message.devices
      status.value = 'connected'
      retries = 0
    }

    opened.onclose = () => {
      if (attempt !== generation) return

      socket = null
      devices.value = []
      scheduleRetry(attempt)
    }
  }

  function connect(): void {
    teardown()
    retries = 0
    void open(generation)
  }

  function disconnect(): void {
    teardown()
    status.value = 'disconnected'
  }

  return { status, devices, activeDevices, connect, disconnect, onSignal }
})
