import * as SecureStore from 'expo-secure-store'
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { Platform } from 'react-native'
import { api, setAuthToken, setUnauthenticatedHandler } from './api'
import type { MemberProfile } from './types'

const TOKEN_KEY = 'gms.member.token'

/**
 * Where the token is kept.
 *
 * SecureStore puts it in the iOS keychain and the Android keystore, so it is
 * encrypted at rest and not readable by other apps. It has no web
 * implementation, and the web build exists only so the app can be developed
 * and reviewed in a browser — so that path falls back to localStorage and is
 * never how a member actually uses this.
 */
const storage = {
  async get(): Promise<string | null> {
    if (Platform.OS === 'web') {
      try {
        return globalThis.localStorage?.getItem(TOKEN_KEY) ?? null
      } catch {
        return null
      }
    }

    return SecureStore.getItemAsync(TOKEN_KEY)
  },

  async set(token: string): Promise<void> {
    if (Platform.OS === 'web') {
      try {
        globalThis.localStorage?.setItem(TOKEN_KEY, token)
      } catch {
        /* private browsing; the session lasts the tab */
      }

      return
    }

    await SecureStore.setItemAsync(TOKEN_KEY, token)
  },

  async clear(): Promise<void> {
    if (Platform.OS === 'web') {
      try {
        globalThis.localStorage?.removeItem(TOKEN_KEY)
      } catch {
        /* ignore */
      }

      return
    }

    await SecureStore.deleteItemAsync(TOKEN_KEY)
  },
}

interface RequestCodeResult {
  channel: string | null
  sentTo: string | null
  expiresInMinutes: number
  /** Local development only — see MemberAuthService on the server. */
  debugCode?: string
}

interface AuthState {
  member: MemberProfile | null
  loading: boolean
  requestCode: (identifier: string) => Promise<RequestCodeResult>
  verify: (identifier: string, code: string) => Promise<void>
  signOut: () => Promise<void>
  refresh: () => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [member, setMember] = useState<MemberProfile | null>(null)
  const [loading, setLoading] = useState(true)

  const forget = useCallback(async () => {
    setAuthToken(null)
    setMember(null)
    await storage.clear()
  }, [])

  /*
   * A stored token may have been revoked from another device or expired, so it
   * is confirmed against the server before the app trusts it. The member sees
   * a splash for one request rather than a home screen built on a session that
   * turns out to be dead.
   */
  useEffect(() => {
    let cancelled = false

    ;(async () => {
      const token = await storage.get()

      if (!token) {
        if (!cancelled) setLoading(false)

        return
      }

      setAuthToken(token)

      try {
        const { data } = await api.get<{ member: MemberProfile }>('/member/me')
        if (!cancelled) setMember(data.member)
      } catch {
        await forget()
      } finally {
        if (!cancelled) setLoading(false)
      }
    })()

    return () => {
      cancelled = true
    }
  }, [forget])

  // The API client calls this when the server rejects our token.
  useEffect(() => {
    setUnauthenticatedHandler(() => {
      void forget()
    })

    return () => setUnauthenticatedHandler(null)
  }, [forget])

  const requestCode = useCallback(async (identifier: string): Promise<RequestCodeResult> => {
    const { data } = await api.post('/member/auth/request-code', { identifier: identifier.trim() })

    return {
      channel: data.channel ?? null,
      sentTo: data.sent_to ?? null,
      expiresInMinutes: data.expires_in_minutes ?? 10,
      debugCode: data.debug_code,
    }
  }, [])

  const verify = useCallback(async (identifier: string, code: string) => {
    const { data } = await api.post<{ token: string; member: MemberProfile }>('/member/auth/verify', {
      identifier: identifier.trim(),
      code: code.trim(),
      device_name: Platform.OS === 'web' ? 'browser' : `${Platform.OS} phone`,
    })

    setAuthToken(data.token)
    await storage.set(data.token)
    setMember(data.member)
  }, [])

  const signOut = useCallback(async () => {
    try {
      await api.post('/member/auth/logout')
    } catch {
      // Clearing the local session must work even if the request fails —
      // otherwise a member on a patchy connection cannot sign out at all. The
      // token stays valid server-side until it expires, which is why the app
      // offers this per-device rather than pretending it is a full revocation.
    }

    await forget()
  }, [forget])

  const refresh = useCallback(async () => {
    const { data } = await api.get<{ member: MemberProfile }>('/member/me')
    setMember(data.member)
  }, [])

  const value = useMemo<AuthState>(
    () => ({ member, loading, requestCode, verify, signOut, refresh }),
    [member, loading, requestCode, verify, signOut, refresh],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside an AuthProvider.')
  return context
}
