import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getWorkspaceSocket } from '../client'

export type SocketStatus = 'connecting' | 'connected' | 'disconnected'

const MAX_RETRY_DELAY_MS = 30_000

export const useDeviceSocketStore = defineStore('deviceSocket', () => {
  const status = ref<SocketStatus>('disconnected')
  const activeDevices = ref(0)

  let socket: WebSocket | null = null
  let retryTimer: ReturnType<typeof setTimeout> | undefined
  let retries = 0
  // Bumped on each connect/disconnect : what an older attempt still has running must be ignored
  let generation = 0

  function teardown(): void {
    generation++
    clearTimeout(retryTimer)
    socket?.close()
    socket = null
    activeDevices.value = 0
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
      if (message.type !== 'devices.count') return

      activeDevices.value = message.count
      status.value = 'connected'
      retries = 0
    }

    opened.onclose = () => {
      if (attempt !== generation) return

      socket = null
      activeDevices.value = 0
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

  return { status, activeDevices, connect, disconnect }
})
