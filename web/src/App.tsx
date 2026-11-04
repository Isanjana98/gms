import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { lazy, type ReactNode } from 'react'
import { ConfirmProvider } from './components/Confirm'
import { Layout } from './components/Layout'
import { ToastProvider } from './components/Toast'
import { Alert, Card, LoadingBlock } from './components/ui'
import { AuthProvider, useAuth } from './lib/auth'
import { CheckInPage } from './pages/CheckInPage'
import { LoginPage } from './pages/LoginPage'
import { MembersPage } from './pages/MembersPage'

/*
 * The check-in desk, member list and sign-in are loaded up front: they are
 * what reception opens first and uses all day.
 *
 * Everything else is split out. The charting library alone is most of the
 * bundle, and a front-desk machine on a slow connection should not download
 * the reporting screens to sign somebody in.
 */
const AccountPage = lazy(() => import('./pages/AccountPage').then((m) => ({ default: m.AccountPage })))
const AttendancePage = lazy(() => import('./pages/AttendancePage').then((m) => ({ default: m.AttendancePage })))
const AuditPage = lazy(() => import('./pages/AuditPage').then((m) => ({ default: m.AuditPage })))
const DashboardPage = lazy(() => import('./pages/DashboardPage').then((m) => ({ default: m.DashboardPage })))
const DevicesPage = lazy(() => import('./pages/DevicesPage').then((m) => ({ default: m.DevicesPage })))
const ExpensesPage = lazy(() => import('./pages/ExpensesPage').then((m) => ({ default: m.ExpensesPage })))
const LeadsPage = lazy(() => import('./pages/LeadsPage').then((m) => ({ default: m.LeadsPage })))
const WebsitePage = lazy(() => import('./pages/WebsitePage').then((m) => ({ default: m.WebsitePage })))
const MemberDetailPage = lazy(() => import('./pages/MemberDetailPage').then((m) => ({ default: m.MemberDetailPage })))
const MembershipsPage = lazy(() => import('./pages/MembershipsPage').then((m) => ({ default: m.MembershipsPage })))
const PaymentsPage = lazy(() => import('./pages/PaymentsPage').then((m) => ({ default: m.PaymentsPage })))
const PlansPage = lazy(() => import('./pages/PlansPage').then((m) => ({ default: m.PlansPage })))
const ReportsPage = lazy(() => import('./pages/ReportsPage').then((m) => ({ default: m.ReportsPage })))
const SchedulePage = lazy(() => import('./pages/SchedulePage').then((m) => ({ default: m.SchedulePage })))
const SettingsPage = lazy(() => import('./pages/SettingsPage').then((m) => ({ default: m.SettingsPage })))
const TrainersPage = lazy(() => import('./pages/TrainersPage').then((m) => ({ default: m.TrainersPage })))
const UsersPage = lazy(() => import('./pages/UsersPage').then((m) => ({ default: m.UsersPage })))

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Reception keeps these screens open all day; refetching on every window
      // focus would hammer the API for data that changes on a human timescale.
      refetchOnWindowFocus: false,
      staleTime: 30_000,
      retry: (failureCount, error) => {
        const status = (error as { response?: { status?: number } })?.response?.status
        // Retrying a 403 or a 422 just repeats the same refusal.
        if (status && status >= 400 && status < 500) return false
        return failureCount < 2
      },
    },
  },
})

function RequireAuth({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return <LoadingBlock label="Checking your session…" />
  if (!user) return <Navigate to="/login" state={{ from: location.pathname }} replace />

  return <>{children}</>
}

/**
 * Routes are gated in the interface as well as on the server. This hides
 * what the user cannot use; the API enforces it independently, so a hand-typed
 * URL gains nothing.
 */
function RequirePermission({ permission, children }: { permission: string; children: ReactNode }) {
  const { can } = useAuth()

  if (!can(permission)) {
    return (
      <Card>
        <Alert tone="warning">
          You do not have permission to view this page. Ask an administrator if you need access.
        </Alert>
      </Card>
    )
  }

  return <>{children}</>
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      {/* Outside the router: a toast raised during navigation should survive
          the route change that caused it. */}
      <ToastProvider>
        <ConfirmProvider>
      {/* Must match Vite's `base`. BASE_URL is injected at build time, and
          React Router wants it without the trailing slash. */}
      <BrowserRouter basename={import.meta.env.BASE_URL.replace(/\/$/, '')}>
        <AuthProvider>
          <Routes>
            <Route path="/login" element={<LoginPage />} />

            <Route
              element={
                <RequireAuth>
                  <Layout />
                </RequireAuth>
              }
            >
              <Route index element={<DashboardPage />} />
              {/* No permission gate: this is the signed-in user's own
                  password and their own sessions. */}
              <Route path="account" element={<AccountPage />} />
              <Route path="check-in" element={<RequirePermission permission="attendance.view"><CheckInPage /></RequirePermission>} />
              <Route path="members" element={<RequirePermission permission="members.view"><MembersPage /></RequirePermission>} />
              <Route path="members/:id" element={<RequirePermission permission="members.view"><MemberDetailPage /></RequirePermission>} />
              <Route path="schedule" element={<RequirePermission permission="workouts.view"><SchedulePage /></RequirePermission>} />
              <Route path="attendance" element={<RequirePermission permission="attendance.view"><AttendancePage /></RequirePermission>} />
              <Route path="memberships" element={<RequirePermission permission="memberships.view"><MembershipsPage /></RequirePermission>} />
              <Route path="payments" element={<RequirePermission permission="payments.view"><PaymentsPage /></RequirePermission>} />
              <Route path="expenses" element={<RequirePermission permission="expenses.view"><ExpensesPage /></RequirePermission>} />
              <Route path="reports" element={<RequirePermission permission="reports.view"><ReportsPage /></RequirePermission>} />
              <Route path="devices" element={<RequirePermission permission="devices.view"><DevicesPage /></RequirePermission>} />
              <Route path="plans" element={<RequirePermission permission="plans.view"><PlansPage /></RequirePermission>} />
              <Route path="trainers" element={<RequirePermission permission="trainers.view"><TrainersPage /></RequirePermission>} />
              <Route path="users" element={<RequirePermission permission="users.manage"><UsersPage /></RequirePermission>} />
              <Route path="website" element={<RequirePermission permission="website.manage"><WebsitePage /></RequirePermission>} />
              <Route path="leads" element={<RequirePermission permission="leads.view"><LeadsPage /></RequirePermission>} />
              <Route path="settings" element={<RequirePermission permission="settings.manage"><SettingsPage /></RequirePermission>} />
              <Route path="audit" element={<RequirePermission permission="audit.view"><AuditPage /></RequirePermission>} />
            </Route>

            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </AuthProvider>
      </BrowserRouter>
        </ConfirmProvider>
      </ToastProvider>
    </QueryClientProvider>
  )
}
