import * as SecureStore from 'expo-secure-store'
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { Platform } from 'react-native'
import { api, setAuthToken, setUnauthenticatedHandler } from './api'
import type { StaffUser } from './types'

const TOKEN_KEY = 'gms.staff.token'

/**
 * Where the token is kept.
 *
 * SecureStore puts it in the iOS keychain and the Android keystore. This
 * matters more here than in the member app: a staff token can read every
 * member's telephone number and the gym's takings, so losing the phone must
 * not mean losing the database.
 *
 * The web fallback exists only so the app can be developed and reviewed in a
 * browser; it is never how staff actually use this.
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

interface AuthState {
  user: StaffUser | null
  loading: boolean
  signIn: (email: string, password: string) => Promise<void>
  signOut: () => Promise<void>
  /** True when the signed-in user holds this permission. */
  can: (permission: string) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<StaffUser | null>(null)
  const [loading, setLoading] = useState(true)

  const forget = useCallback(async () => {
    setAuthToken(null)
    setUser(null)
    await storage.clear()
  }, [])

  /*
   * A stored token may have been revoked from the admin panel, or the account
   * deactivated. It is confirmed against the server before the app trusts it —
   * a staff tool must not show a dashboard built on a session that has been
   * withdrawn.
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
        const { data } = await api.get<{ user: StaffUser }>('/auth/me')
        if (!cancelled) setUser(data.user)
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

  useEffect(() => {
    setUnauthenticatedHandler(() => {
      void forget()
    })

    return () => setUnauthenticatedHandler(null)
  }, [forget])

  const signIn = useCallback(async (email: string, password: string) => {
    /*
     * `issue_token: true` asks for a bearer token instead of the httpOnly
     * cookie the admin panel uses. A phone app is not a browser: it has no
     * cookie jar worth relying on, and bearer tokens are deliberately exempt
     * from the CSRF header check for exactly this case.
     */
    const { data } = await api.post<{ token: string; user: StaffUser }>('/auth/login', {
      email: email.trim(),
      password,
      issue_token: true,
      device_name: Platform.OS === 'web' ? 'staff browser' : `staff ${Platform.OS}`,
    })

    setAuthToken(data.token)
    await storage.set(data.token)
    setUser(data.user)
  }, [])

  const signOut = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // Clearing the local session must work even if the request fails.
      // Otherwise a receptionist on a patchy connection cannot sign out of a
      // phone that holds the member database.
    }

    await forget()
  }, [forget])

  const value = useMemo<AuthState>(
    () => ({
      user,
      loading,
      signIn,
      signOut,
      // The app hides what a user cannot do; the API enforces it independently,
      // so a hidden button is a courtesy and not the control.
      can: (permission) => user?.permissions?.includes(permission) ?? false,
    }),
    [user, loading, signIn, signOut],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside an AuthProvider.')
  return context
}

/**
 * Permission names, mirroring App\Support\Permissions on the server.
 *
 * Spelled out in full rather than composed from parts: a typo in a literal
 * fails closed and hides a button, which somebody reports. A typo in a
 * generated string tends to fail in whichever direction the bug happens to
 * take it, and nobody reports a button they were never meant to see.
 */
export const P = {
  membersView: 'members.view',
  membersCreate: 'members.create',
  membersUpdate: 'members.update',
  membersDelete: 'members.delete',

  documentsView: 'documents.view',
  documentsManage: 'documents.manage',

  plansView: 'plans.view',
  plansManage: 'plans.manage',

  membershipsView: 'memberships.view',
  membershipsCreate: 'memberships.create',
  membershipsRenew: 'memberships.renew',
  membershipsCancel: 'memberships.cancel',

  paymentsView: 'payments.view',
  paymentsCreate: 'payments.create',
  paymentsRefund: 'payments.refund',
  invoicesView: 'invoices.view',

  expensesView: 'expenses.view',
  expensesManage: 'expenses.manage',
  expenseCategoriesManage: 'expense-categories.manage',

  attendanceView: 'attendance.view',
  attendanceManage: 'attendance.manage',

  devicesView: 'devices.view',
  devicesManage: 'devices.manage',
  devicesSync: 'devices.sync',
  enrollmentManage: 'enrollment.manage',

  trainersView: 'trainers.view',
  trainersManage: 'trainers.manage',

  workoutsView: 'workouts.view',
  workoutsManage: 'workouts.manage',
  workoutsViewAll: 'workouts.view-all',

  reportsView: 'reports.view',
  reportsFinancial: 'reports.financial',
  auditView: 'audit.view',

  usersManage: 'users.manage',
  rolesManage: 'roles.manage',
  branchesManage: 'branches.manage',
  branchesViewAll: 'branches.view-all',

  settingsManage: 'settings.manage',
  brandingManage: 'branding.manage',
  websiteManage: 'website.manage',

  leadsView: 'leads.view',
  leadsManage: 'leads.manage',

  backupsManage: 'backups.manage',
  dataReset: 'data.reset',
} as const
