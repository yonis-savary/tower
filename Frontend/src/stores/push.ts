import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { claimPushBrowser, deletePushSubscription, getPushConfiguration, listPushSubscriptions, savePushSubscription, type ListPushSubscriptionsResponse } from '../client'
import { decodeBase64Url, isIos, isStandalone, sameKey } from '../utils/push'

export type PushState = 'loading' | 'unconfigured' | 'unsupported' | 'ios' | 'denied' | 'disabled' | 'enabled'

const WORKER_URL = '/sw.js'

/**
 * Push notifications on this browser : its subscription, enabled workspace by workspace, and the
 * browsers of the user in the current workspace.
 */
export const usePushStore = defineStore('push', () => {
  // null until the server answered
  const configured = ref<boolean | null>(null)
  const publicKey = ref<string | null>(null)
  const permission = ref<NotificationPermission>(typeof Notification === 'undefined' ? 'default' : Notification.permission)
  // The endpoint of this browser, when subscribed : enabled in a workspace once listed there
  const endpoint = ref<string | null>(null)
  // null while the browsers of the current workspace load
  const subscriptions = ref<ListPushSubscriptionsResponse | null>(null)
  let listening = false

  const supported = typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

  const state = computed<PushState>(() => {
    if (configured.value === null) return 'loading'
    if (!configured.value) return 'unconfigured'
    if (!supported) return isIos() && !isStandalone() ? 'ios' : 'unsupported'
    if (permission.value === 'denied') return 'denied'

    if (endpoint.value && subscriptions.value === null) return 'loading'

    return subscriptions.value?.some((item) => item.endpoint === endpoint.value) ? 'enabled' : 'disabled'
  })

  /**
   * Once logged in : registers the service worker, and gives this browser to the user logged in
   */
  async function start(onNavigate: (url: string) => void): Promise<void> {
    const { data } = await getPushConfiguration()
    configured.value = data?.enabled ?? false
    publicKey.value = data?.public_key ?? null
    endpoint.value = null
    if (!configured.value || !supported || !publicKey.value) return

    if (!listening) {
      listening = true
      navigator.serviceWorker.addEventListener('message', (event: MessageEvent) => {
        if (event.data?.type === 'tower.navigate' && typeof event.data.url === 'string') onNavigate(event.data.url)
      })
    }

    const registration = await navigator.serviceWorker.register(WORKER_URL)
    const subscription = await registration.pushManager.getSubscription()
    if (!subscription) return

    // New VAPID keys on the server : the old subscription can no longer receive anything
    if (!sameKey(subscription.options.applicationServerKey, decodeBase64Url(publicKey.value))) {
      await subscription.unsubscribe()
      return
    }

    if (permission.value !== 'granted') return

    endpoint.value = subscription.endpoint
    await claimPushBrowser({ body: { endpoint: subscription.endpoint } })
  }

  /** In the current workspace, on a click : browsers only ask for the permission after a gesture of the user */
  async function enable(): Promise<boolean> {
    if (!publicKey.value || !supported) return false

    permission.value = await Notification.requestPermission()
    if (permission.value !== 'granted') return false

    const registration = await navigator.serviceWorker.ready
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: decodeBase64Url(publicKey.value),
    })
    if (!(await send(subscription))) return false

    endpoint.value = subscription.endpoint
    await loadSubscriptions()

    return true
  }

  /** Stops the notifications of the current workspace on this browser : the others keep its subscription */
  async function disable(): Promise<void> {
    const own = subscriptions.value?.find((item) => item.endpoint === endpoint.value)
    if (own) await deletePushSubscription({ path: { subscription: own.id } })

    await loadSubscriptions()
  }

  /** The browsers of the current workspace, to load again after switching workspace */
  async function loadSubscriptions(): Promise<boolean> {
    subscriptions.value = null
    const { data } = await listPushSubscriptions()
    subscriptions.value = data ?? []

    return Boolean(data)
  }

  async function send(subscription: PushSubscription): Promise<boolean> {
    const { endpoint, keys } = subscription.toJSON()
    if (!endpoint || !keys?.p256dh || !keys.auth) return false

    const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings ?? []
    const { response } = await savePushSubscription({
      body: {
        endpoint,
        keys: { p256dh: keys.p256dh, auth: keys.auth },
        content_encoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
      },
    })

    return Boolean(response?.ok)
  }

  return { configured, state, endpoint, subscriptions, start, enable, disable, loadSubscriptions }
})
