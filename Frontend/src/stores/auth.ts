import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getCurrentUser, login, logout, register, type GetCurrentUserResponse } from '../client'

export type Credentials = { email: string; password: string }

/** `ok` when a session is open, else the HTTP status of the refusal (`null` when the server is unreachable) */
export type AuthResult = { ok: true } | { ok: false; status: number | null }

export const useAuthStore = defineStore('auth', () => {
  const user = ref<GetCurrentUserResponse | null>(null)
  let sessionLoaded = false

  async function loadSession(): Promise<void> {
    if (sessionLoaded) return

    const { data } = await getCurrentUser()
    user.value = data ?? null
    sessionLoaded = true
  }

  async function signIn(body: Credentials): Promise<AuthResult> {
    const { data, response } = await login({ body })
    if (!data) return { ok: false, status: response?.status ?? null }

    user.value = data
    return { ok: true }
  }

  async function signUp(body: Credentials): Promise<AuthResult> {
    const { data, response } = await register({ body })
    if (!data) return { ok: false, status: response?.status ?? null }

    user.value = data
    return { ok: true }
  }

  async function signOut(): Promise<void> {
    await logout()
    user.value = null
  }

  return { user, loadSession, signIn, signUp, signOut }
})
