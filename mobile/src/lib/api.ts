import axios, { type AxiosError } from 'axios'
import Constants from 'expo-constants'
import { Platform } from 'react-native'

/**
 * Where the API lives.
 *
 * Android emulators cannot reach the host machine on `localhost` — that is the
 * emulated device itself — so they use 10.0.2.2, which Android maps to the
 * host. iOS simulators and the web build share the host's network stack and
 * use localhost. A real phone needs the machine's LAN address, which is why
 * this is overridable from app.json rather than hard-coded.
 */
function resolveBaseUrl(): string {
  const configured =
    (Constants.expoConfig?.extra as { apiUrl?: string } | undefined)?.apiUrl ?? 'http://localhost:8000/api'

  /*
   * One setting, corrected per platform.
   *
   * An Android emulator cannot reach the host machine on `localhost` — that
   * address is the emulated device itself. Android maps 10.0.2.2 to the host
   * instead, so the swap happens here rather than forcing two config values
   * that can drift apart.
   *
   * A real phone needs the machine's LAN address (or a public URL), which is
   * what `extra.apiUrl` is for.
   */
  if (Platform.OS === 'android') {
    return configured.replace('localhost', '10.0.2.2').replace('127.0.0.1', '10.0.2.2')
  }

  return configured
}

export const API_URL = resolveBaseUrl()

export const api = axios.create({
  baseURL: API_URL,
  headers: { Accept: 'application/json' },
  // A gym's wifi is not a data centre's. Better a clear failure than a
  // spinner that never resolves.
  timeout: 15000,
})

/**
 * Called when the server rejects our token.
 *
 * Set by the auth provider rather than imported from it, because the provider
 * imports this module — wiring it the other way round is a cycle that Metro
 * resolves to `undefined` at runtime.
 */
let onUnauthenticated: (() => void) | null = null

export function setUnauthenticatedHandler(handler: (() => void) | null) {
  onUnauthenticated = handler
}

export function setAuthToken(token: string | null) {
  if (token) {
    api.defaults.headers.common.Authorization = `Bearer ${token}`
  } else {
    delete api.defaults.headers.common.Authorization
  }
}

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    const status = error.response?.status
    const url = error.config?.url ?? ''

    // A 401 on the sign-in endpoints is an ordinary "wrong code", not an
    // expired session, and must not sign the member out mid-attempt.
    const isAuthAttempt = url.includes('/auth/request-code') || url.includes('/auth/verify')

    if (status === 401 && !isAuthAttempt) {
      onUnauthenticated?.()
    }

    return Promise.reject(error)
  },
)

type LaravelError = {
  message?: string
  errors?: Record<string, string[]>
}

/**
 * Turn any failure into one sentence worth showing someone.
 *
 * The API already writes refusals as sentences meant for a person — "That code
 * is not right, or it has expired" — so the job here is mostly to find them and
 * to say something useful when the server cannot be reached at all.
 */
export function errorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  const axiosError = error as AxiosError<LaravelError>

  if (axiosError?.response) {
    const data = axiosError.response.data

    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    if (firstFieldError) return firstFieldError

    if (data?.message) return data.message

    if (axiosError.response.status === 403) return 'You do not have access to that.'
    if (axiosError.response.status === 404) return 'Not found.'
  }

  if (axiosError?.request) {
    return `Cannot reach the gym's system. Check your connection.`
  }

  return fallback
}
