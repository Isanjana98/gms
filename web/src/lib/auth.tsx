import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api } from './api'
import type { AuthUser } from './types'

interface AuthState {
  user: AuthUser | null
  loading: boolean
  signIn: (email: string, password: string) => Promise<void>
  signOut: () => Promise<void>
  /** True when the signed-in user holds this permission (SRS 3). */
  can: (permission: string) => boolean
  hasRole: (role: string) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

/**
 * Whether the server has told us a session exists.
 *
 * Both spellings are checked because the session cookie takes the browser's
 * __Host- prefix over HTTPS and cannot over plain HTTP, and the companion
 * cookie follows it.
 */
function hasSessionHint(): boolean {
  try {
    return /(?:^|;\s*)(?:__Host-)?gms_session_active=1/.test(document.cookie)
  } catch {
    // document.cookie can throw where cookies are blocked entirely. Asking
    // the server is the safe fallback: worst case it answers 401.
    return true
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(true)

  /*
   * The session is an httpOnly cookie, so this code cannot read it — it can
   * only ask the server whether it is valid.
   *
   * The server also sets a readable companion cookie that says nothing except
   * "a session exists". Checking it first means an anonymous visitor goes
   * straight to the sign-in screen instead of making a request that can only
   * come back 401. The cookie holds no secret and forging it gains nothing:
   * the answer is then simply an unauthenticated /auth/me.
   */
  useEffect(() => {
    if (!hasSessionHint()) {
      setLoading(false)
      return
    }

    api
      .get<{ user: AuthUser }>('/auth/me')
      // The hint can outlive the session — revoked from another device, or
      // timed out — so the server's answer is what decides.
      .then(({ data }) => setUser(data.user))
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  const signIn = useCallback(async (email: string, password: string) => {
    // No token comes back. The server sets the cookie; the browser stores it
    // somewhere this code cannot reach, which is the point.
    const { data } = await api.post<{ user: AuthUser }>('/auth/login', {
      email,
      password,
      device_name: 'web',
    })

    setUser(data.user)
  }, [])

  const signOut = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // Clearing the local state must work even if the request fails -
      // otherwise a network blip leaves someone apparently signed in on a
      // shared reception terminal. The cookie is httpOnly, so only the server
      // can actually remove it; a failure here leaves a session that is still
      // valid until it expires, which is why the sessions screen exists.
    }

    setUser(null)
  }, [])

  const value = useMemo<AuthState>(
    () => ({
      user,
      loading,
      signIn,
      signOut,
      can: (permission) => user?.permissions?.includes(permission) ?? false,
      hasRole: (role) => user?.roles?.includes(role) ?? false,
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

/** Permission names, mirroring App\Support\Permissions on the server. */
export const P = {
  membersView: 'members.view',
  membersCreate: 'members.create',
  membersUpdate: 'members.update',
  membersDelete: 'members.delete',
  documentsView: 'documents.view',

  // Health screening is deliberately not under documents.*: whoever may file
  // an ID copy should not automatically learn that a member has a heart
  // condition.
  healthView: 'health.view',
  healthIssue: 'health.issue',
  healthReview: 'health.review',
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
  devicesOpenDoor: 'devices.open_door',
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
  branchesManage: 'branches.manage',
  settingsManage: 'settings.manage',
  brandingManage: 'branding.manage',
  websiteManage: 'website.manage',
  leadsView: 'leads.view',
  leadsManage: 'leads.manage',
  backupsManage: 'backups.manage',
  dataReset: 'data.reset',
} as const
