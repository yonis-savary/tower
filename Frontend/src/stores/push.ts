import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { deletePushSubscription, getPushConfiguration, listPushSubscriptions, savePushSubscription, type ListPushSubscriptionsResponse } from '../client'
import { decodeBase64Url, isIos, isStandalone, sameKey } from '../utils/push'

export type PushState = 'loading' | 'unconfigured' | 'unsupported' | 'ios' | 'denied' | 'disabled' | 'enabled'

const WORKER_URL = '/sw.js'

/**
 * Push notifications on this browser : its subscription, sent to Tower each time it opens, and
 * the browsers of the user.
 */
export const usePushStore = defineStore('push', () => {
  // null until the server answered
  const configured = ref<boolean | null>(null)
  const publicKey = ref<string | null>(null)
  const permission = ref<NotificationPermission>(typeof Notification === 'undefined' ? 'default' : Notification.permission)
  // The endpoint of this browser, when subscribed
  const endpoint = ref<string | null>(null)
  const subscriptions = ref<ListPushSubscriptionsResponse>([])
  let listening = false

  const supported = typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

  const state = computed<PushState>(() => {
    if (configured.value === null) return 'loading'
    if (!configured.value) return 'unconfigured'
    if (!supported) return isIos() && !isStandalone() ? 'ios' : 'unsupported'
    if (permission.value === 'denied') return 'denied'

    return endpoint.value ? 'enabled' : 'disabled'
  })

  /**
   * Once logged in : registers the service worker and sends the subscription of this browser again,
   * which then belongs to the user logged in
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

    if (permission.value === 'granted' && (await send(subscription))) endpoint.value = subscription.endpoint
  }

  /** On a click : browsers only ask for the permission after a gesture of the user */
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

  /** Stops the notifications on this browser */
  async function disable(): Promise<void> {
    const registration = await navigator.serviceWorker.getRegistration(WORKER_URL)
    const subscription = await registration?.pushManager.getSubscription()
    await subscription?.unsubscribe()

    const own = subscriptions.value.find((item) => item.endpoint === endpoint.value)
    if (own) await deletePushSubscription({ path: { subscription: own.id } })

    endpoint.value = null
    await loadSubscriptions()
  }

  async function loadSubscriptions(): Promise<boolean> {
    const { data } = await listPushSubscriptions()
    if (data) subscriptions.value = data

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
