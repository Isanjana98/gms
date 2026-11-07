import { Suspense, useState, type ReactNode } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { P, useAuth } from '../lib/auth'
import { BrandLogo, useBranding } from '../lib/branding'
import { DoorButton } from './DoorButton'
import { Avatar, Button, LoadingBlock, cx } from './ui'

interface NavItem {
  to: string
  label: string
  icon: ReactNode
  permission?: string
  end?: boolean
}

function Icon({ path }: { path: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" className="h-[18px] w-[18px] shrink-0">
      <path d={path} strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

const ICONS = {
  dashboard: 'M4 13h6V4H4v9zm0 7h6v-5H4v5zm10 0h6v-9h-6v9zm0-16v5h6V4h-6z',
  checkIn: 'M15 12H3m0 0l4-4m-4 4l4 4M21 4v16',
  members: 'M17 20h5v-1a4 4 0 00-4-4M9 12a4 4 0 100-8 4 4 0 000 8zm8 0a3 3 0 100-6 3 3 0 000 6zM2 20v-1a6 6 0 0114 0v1',
  attendance: 'M8 7V3m8 4V3M4 11h16M5 21h14a1 1 0 001-1V6a1 1 0 00-1-1H5a1 1 0 00-1 1v14a1 1 0 001 1z',
  schedule: 'M8 7V3m8 4V3M4 11h16M5 21h14a1 1 0 001-1V6a1 1 0 00-1-1H5a1 1 0 00-1 1v14a1 1 0 001 1zM9 15h2m3 0h2',
  memberships: 'M4 6h16M4 12h16M4 18h10',
  payments: 'M3 10h18M6 15h4M4 6h16a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V7a1 1 0 011-1z',
  // A receipt with a downward arrow: money leaving, against the card that
  // means money arriving.
  expenses: 'M9 14l3 3 3-3M12 17V9M6 3h12a1 1 0 011 1v16l-3-2-2 2-2-2-2 2-3-2V4a1 1 0 011-1z',
  website: 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.3 3.8-5.3 3.8-9S14.5 5.3 12 3M12 21c-2.5-2.3-3.8-5.3-3.8-9S9.5 5.3 12 3M3.6 9h16.8M3.6 15h16.8',
  leads: 'M3 8l9 6 9-6M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z',
  devices: 'M12 11a3 3 0 013 3v6m-3-9a3 3 0 00-3 3v6M7 8a5 5 0 0110 0M4 9a8 8 0 0116 0',
  reports: 'M7 16v-5m5 5V8m5 8v-3M5 21h14a1 1 0 001-1V4a1 1 0 00-1-1H5a1 1 0 00-1 1v16a1 1 0 001 1z',
  trainers: 'M6.5 6.5h11M6.5 17.5h11M4 9v6m16-6v6M8 8v8m8-8v8',
  plans: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
  settings:
    'M10.3 3.3a1 1 0 011.4 0l1 1a1 1 0 00.9.3l1.4-.2a1 1 0 011.1.7l.5 1.3a1 1 0 00.6.6l1.3.5a1 1 0 01.7 1.1l-.2 1.4a1 1 0 00.3.9l1 1a1 1 0 010 1.4l-1 1a1 1 0 00-.3.9l.2 1.4a1 1 0 01-.7 1.1l-1.3.5a1 1 0 00-.6.6l-.5 1.3a1 1 0 01-1.1.7l-1.4-.2a1 1 0 00-.9.3l-1 1a1 1 0 01-1.4 0l-1-1a1 1 0 00-.9-.3l-1.4.2a1 1 0 01-1.1-.7l-.5-1.3a1 1 0 00-.6-.6l-1.3-.5a1 1 0 01-.7-1.1l.2-1.4a1 1 0 00-.3-.9l-1-1a1 1 0 010-1.4l1-1a1 1 0 00.3-.9l-.2-1.4a1 1 0 01.7-1.1l1.3-.5a1 1 0 00.6-.6l.5-1.3a1 1 0 011.1-.7l1.4.2a1 1 0 00.9-.3l1-1zM12 15a3 3 0 100-6 3 3 0 000 6z',
  audit: 'M9 12l2 2 4-4M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z',
  users: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM3 21v-1a7 7 0 0114 0v1',
}

const NAV: Array<{ section: string; items: NavItem[] }> = [
  {
    section: 'Daily',
    items: [
      { to: '/', label: 'Dashboard', icon: <Icon path={ICONS.dashboard} />, end: true },
      { to: '/check-in', label: 'Check-in desk', icon: <Icon path={ICONS.checkIn} />, permission: P.attendanceView },
      { to: '/members', label: 'Members', icon: <Icon path={ICONS.members} />, permission: P.membersView },
      { to: '/schedule', label: 'Gym schedules', icon: <Icon path={ICONS.schedule} />, permission: P.workoutsView },
      { to: '/attendance', label: 'Attendance', icon: <Icon path={ICONS.attendance} />, permission: P.attendanceView },
    ],
  },
  {
    section: 'Money',
    items: [
      {
        to: '/memberships',
        label: 'Memberships',
        icon: <Icon path={ICONS.memberships} />,
        permission: P.membershipsView,
      },
      { to: '/payments', label: 'Payments', icon: <Icon path={ICONS.payments} />, permission: P.paymentsView },
      { to: '/expenses', label: 'Expenses', icon: <Icon path={ICONS.expenses} />, permission: P.expensesView },
      { to: '/reports', label: 'Reports', icon: <Icon path={ICONS.reports} />, permission: P.reportsView },
    ],
  },
  {
    // The public side of the business, kept apart from the day-to-day
    // operational screens: different job, often a different person.
    section: 'Website',
    items: [
      { to: '/leads', label: 'Enquiries', icon: <Icon path={ICONS.leads} />, permission: P.leadsView },
      { to: '/website', label: 'Website', icon: <Icon path={ICONS.website} />, permission: P.websiteManage },
    ],
  },
  {
    section: 'Setup',
    items: [
      { to: '/devices', label: 'Fingerprint devices', icon: <Icon path={ICONS.devices} />, permission: P.devicesView },
      { to: '/plans', label: 'Membership plans', icon: <Icon path={ICONS.plans} />, permission: P.plansView },
      { to: '/trainers', label: 'Trainers', icon: <Icon path={ICONS.trainers} />, permission: P.trainersView },
      { to: '/users', label: 'Staff & roles', icon: <Icon path={ICONS.users} />, permission: P.usersManage },
      { to: '/settings', label: 'Settings', icon: <Icon path={ICONS.settings} />, permission: P.settingsManage },
      { to: '/audit', label: 'Audit log', icon: <Icon path={ICONS.audit} />, permission: P.auditView },
    ],
  },
]

export function Layout() {
  const { user, signOut, can } = useAuth()
  const branding = useBranding()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)

  const handleSignOut = async () => {
    await signOut()
    navigate('/login')
  }

  // A section with nothing the user may open is hidden entirely, so a
  // receptionist does not scan past three headings of links they cannot use.
  const sections = NAV.map((section) => ({
    ...section,
    items: section.items.filter((item) => !item.permission || can(item.permission)),
  })).filter((section) => section.items.length > 0)

  return (
    <div className="flex min-h-full">
      {menuOpen && (
        <button
          type="button"
          aria-label="Close menu"
          className="fixed inset-0 z-30 bg-ink-900/40 lg:hidden"
          onClick={() => setMenuOpen(false)}
        />
      )}

      {/*
        Sticky rather than static from `lg` up, so the sidebar keeps its own
        scroll instead of riding the page.

        It used to be `lg:static`, which put it in normal flow: scrolling a long
        members list carried the brand, the navigation and Sign out off the top
        of the screen, and getting back to them meant scrolling all the way up
        again. Pinned to the viewport at full height, the page scrolls under it
        and the `nav` below scrolls inside it when the links outgrow the space.

        Below `lg` it stays `fixed`, which is what the slide-in drawer needs.
      */}
      <aside
        className={cx(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-ink-200 bg-white transition-transform',
          'lg:sticky lg:inset-y-auto lg:top-0 lg:h-dvh lg:translate-x-0',
          menuOpen ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <div className="flex h-14 items-center gap-2.5 border-b border-ink-200 px-5">
          <BrandLogo branding={branding} />
          <span className="truncate text-sm font-semibold tracking-tight text-ink-900">{branding.gym_name}</span>
        </div>

        <nav className="flex-1 overflow-y-auto px-3 py-4">
          {sections.map((section) => (
            <div key={section.section} className="mb-5">
              <p className="px-2 pb-1.5 text-[10px] font-semibold uppercase tracking-wider text-ink-400">
                {section.section}
              </p>
              <ul className="space-y-0.5">
                {section.items.map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      end={item.end}
                      onClick={() => setMenuOpen(false)}
                      className={({ isActive }) =>
                        cx(
                          'flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition',
                          isActive
                            ? 'bg-brand-50 text-brand-700'
                            : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900',
                        )
                      }
                    >
                      {item.icon}
                      {item.label}
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>

        <div className="border-t border-ink-200 p-3">
          {/* The whole block is the link to the account screen — the name is
              where people look for their own settings. */}
          <NavLink
            to="/account"
            onClick={() => setMenuOpen(false)}
            className={({ isActive }) =>
              cx(
                'flex items-center gap-2.5 rounded-lg px-2 py-2 transition',
                isActive ? 'bg-brand-50' : 'hover:bg-ink-100',
              )
            }
          >
            <Avatar name={user?.name} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-ink-900">{user?.name}</p>
              <p className="truncate text-xs text-ink-500">{user?.roles?.[0] ?? '—'}</p>
            </div>
          </NavLink>
          <Button variant="secondary" size="sm" className="mt-2 w-full" onClick={handleSignOut}>
            Sign out
          </Button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-14 items-center gap-3 border-b border-ink-200 bg-white px-4 lg:hidden no-print">
          <button
            type="button"
            onClick={() => setMenuOpen(true)}
            className="rounded-lg p-2 text-ink-600 hover:bg-ink-100"
            aria-label="Open menu"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5">
              <path d="M4 6h16M4 12h16M4 18h16" strokeLinecap="round" />
            </svg>
          </button>
          <BrandLogo branding={branding} className="h-7 w-7 text-xs" />
          <span className="truncate text-sm font-semibold">{branding.gym_name}</span>
        </header>

        <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
          {/*
            Fills the available width rather than capping at 1280px.
            This is a dashboard of tables and cards, not an article: someone
            on a wide monitor wants more columns visible, not 190px of empty
            page on each side. The cap is only there to stop rows stretching
            to absurd lengths on an ultrawide display.
          */}
          <div className="mx-auto w-full max-w-[1800px]">
            {/* Inside the layout so a lazily-loaded page does not blank the
                sidebar on every navigation. */}
            <Suspense fallback={<LoadingBlock />}>
              <Outlet />
            </Suspense>
          </div>
        </main>
      </div>

      {/* Outside <main> so it is not bound by the content column, and last in
          the tree so it stacks above it. It renders nothing for staff without
          the permission. */}
      <DoorButton />
    </div>
  )
}
