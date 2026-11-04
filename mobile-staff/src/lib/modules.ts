import { P } from './auth'

/**
 * Every module the app can open, and who may open it.
 *
 * One list, because the alternative is a permission spelled one way in the
 * menu and another way in the screen — which fails open, showing a tile that
 * dead-ends in a 403. The menu and the guard on each screen both read from
 * here, so they cannot disagree.
 *
 * Order is the order they appear under **More**, grouped the way somebody
 * thinks about the gym rather than the way the API is organised.
 */

export type Module = {
  key: string
  title: string
  description: string
  icon: string
  route: string
  /** Any one of these is enough to see it. */
  permissions: string[]
  group: 'People' | 'Money' | 'Training' | 'The gym' | 'Administration'
}

export const MODULES: Module[] = [
  // ---------------------------------------------------------------- people
  {
    key: 'memberships',
    title: 'Memberships',
    description: 'Terms, renewals and suspensions',
    icon: '▤',
    route: '/memberships',
    permissions: [P.membershipsView],
    group: 'People',
  },
  {
    key: 'leads',
    title: 'Enquiries',
    description: 'People who asked about joining',
    icon: '✉',
    route: '/leads',
    permissions: [P.leadsView],
    group: 'People',
  },
  {
    key: 'trainers',
    title: 'Trainers',
    description: 'Staff who take sessions',
    icon: '♟',
    route: '/trainers',
    permissions: [P.trainersView],
    group: 'People',
  },

  // ----------------------------------------------------------------- money
  {
    key: 'payments',
    title: 'Payments',
    description: 'Takings, receipts and refunds',
    icon: '₨',
    route: '/payments',
    permissions: [P.paymentsView],
    group: 'Money',
  },
  {
    key: 'invoices',
    title: 'Invoices',
    description: 'What has been billed',
    icon: '▦',
    route: '/invoices',
    permissions: [P.invoicesView],
    group: 'Money',
  },
  {
    key: 'expenses',
    title: 'Expenses',
    description: 'What the gym spends',
    icon: '▽',
    route: '/expenses',
    permissions: [P.expensesView],
    group: 'Money',
  },
  {
    key: 'plans',
    title: 'Plans',
    description: 'What a membership costs',
    icon: '◈',
    route: '/plans',
    permissions: [P.plansView],
    group: 'Money',
  },

  {
    key: 'reports',
    title: 'Reports',
    description: 'Attendance, revenue, outstanding, P&L',
    icon: '◫',
    route: '/reports',
    permissions: [P.reportsView],
    group: 'Money',
  },

  // -------------------------------------------------------------- training
  {
    key: 'workouts',
    title: 'Workout plans',
    description: 'Programmes assigned to members',
    icon: '≡',
    route: '/workouts',
    permissions: [P.workoutsView],
    group: 'Training',
  },

  // --------------------------------------------------------------- the gym
  {
    key: 'attendance',
    title: 'Attendance',
    description: 'The visit log, and corrections',
    icon: '◉',
    route: '/attendance',
    permissions: [P.attendanceView],
    group: 'The gym',
  },
  {
    key: 'devices',
    title: 'Devices',
    description: 'Fingerprint readers and sync',
    icon: '⎔',
    route: '/devices',
    permissions: [P.devicesView],
    group: 'The gym',
  },
  {
    key: 'website',
    title: 'Website',
    description: 'Pages, posts and the timetable',
    icon: '◱',
    route: '/website',
    permissions: [P.websiteManage],
    group: 'The gym',
  },

  // -------------------------------------------------------- administration
  {
    key: 'users',
    title: 'Staff users',
    description: 'Who can sign in, and as what',
    icon: '☖',
    route: '/users',
    permissions: [P.usersManage],
    group: 'Administration',
  },
  {
    key: 'branches',
    title: 'Branches',
    description: 'Locations this system covers',
    icon: '⌂',
    route: '/branches',
    permissions: [P.branchesManage],
    group: 'Administration',
  },
  {
    key: 'settings',
    title: 'Settings',
    description: 'How the gym runs',
    icon: '⚙',
    route: '/settings',
    permissions: [P.settingsManage],
    group: 'Administration',
  },
  {
    key: 'audit',
    title: 'Audit log',
    description: 'Who changed what, and when',
    icon: '◷',
    route: '/audit',
    permissions: [P.auditView],
    group: 'Administration',
  },
  {
    key: 'backups',
    title: 'Backups',
    description: 'Copies of the database',
    icon: '⇩',
    route: '/backups',
    permissions: [P.backupsManage],
    group: 'Administration',
  },
  {
    key: 'account',
    title: 'Your account',
    description: 'Password and signed-in devices',
    icon: '☺',
    route: '/account',
    permissions: [],
    group: 'Administration',
  },
]

export const MODULE_GROUPS: Module['group'][] = [
  'People',
  'Money',
  'Training',
  'The gym',
  'Administration',
]

/** The modules this user may open, in menu order. */
export function modulesFor(can: (permission: string) => boolean): Module[] {
  return MODULES.filter((m) => m.permissions.length === 0 || m.permissions.some(can))
}
