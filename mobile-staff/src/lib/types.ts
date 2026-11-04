/** Shapes returned by the staff API. */

export interface StaffUser {
  id: number
  name: string
  email: string
  branch_id: number | null
  branch: { id: number; name: string } | null
  is_multi_branch: boolean
  roles: string[]
  permissions: string[]
  last_login_at?: string | null
}

export interface DashboardSummary {
  generated_at: string
  members: { total: number; active: number; expired: number; new_this_month: number; new_today: number }
  attendance: { today: number; currently_in: number; yesterday: number }
  revenue: { today: number; this_month: number; outstanding: number }
  memberships: { active: number; pending_payment: number; expiring_soon: number }
}

export interface MemberSummary {
  id: number
  membership_number: string
  full_name: string
  telephone?: string | null
  email?: string | null
  status: string
  status_label?: string
  photo_url?: string | null
  membership_expires_on?: string | null
  total_visits?: number
  last_visit_at?: string | null
  current_membership?: {
    reference: string
    plan_name: string
    end_date: string
    days_remaining: number
    balance: number
    status: string
  } | null
}

/**
 * The answer to "would this person be let in right now?".
 *
 * Returned without recording anything, so the desk can start a renewal
 * conversation instead of logging a refused entry.
 */
export interface EntryValidation {
  valid: boolean
  message: string
  currently_checked_in: boolean
  checked_in_at: string | null
  member: { id: number; full_name: string; membership_number: string; status: string }
  membership: {
    reference: string
    plan_name: string
    end_date: string
    days_remaining: number
  } | null
}

export interface CheckInOutcome {
  message: string
  accepted: boolean
  result: 'PROCESSED' | 'DUPLICATE' | 'UNMATCHED' | 'REJECTED' | 'ERROR'
}

export interface LiveVisit {
  id: number
  member_id: number
  member?: { id: number; full_name: string; membership_number: string } | null
  check_in_time: string | null
  duration_minutes: number | null
}

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number }
  current_page?: number
  last_page?: number
  total?: number
}

/*
 * The rest of the API, as it actually answers.
 *
 * Written from real responses rather than from the models: several resources
 * rename on the way out — an expense is `expense_date` and `description`, not
 * `spent_on` and `notes` — and a type that describes the database instead of
 * the payload compiles perfectly while rendering nothing.
 */

/** A person as most list endpoints embed them. */
export interface EmbeddedMember {
  id: number
  membership_number: string
  full_name: string
  telephone?: string | null
  email?: string | null
}

/* ----------------------------------------------------------------- money */

export interface Payment {
  id: number
  member_id: number
  invoice_id?: number | null
  membership_id?: number | null
  receipt_number: string
  payment_type: string
  payment_type_label?: string
  amount: number
  discount?: number
  method: string
  method_label?: string
  reference?: string | null
  paid_at: string | null
  status: string
  is_refund?: boolean
  notes?: string | null
  member?: EmbeddedMember | null
  received_by?: { id: number; name: string } | null
}

export interface Invoice {
  id: number
  member_id: number
  invoice_number: string
  issue_date: string | null
  due_date: string | null
  sub_total: number
  discount: number
  tax: number
  total: number
  paid_amount: number
  balance: number
  status: string
  notes?: string | null
  member?: EmbeddedMember | null
}

export interface Expense {
  id: number
  expense_category_id: number
  reference?: string | null
  expense_date: string
  amount: number
  tax_amount?: number
  total: number
  method: string
  method_label?: string
  payee?: string | null
  supplier_reference?: string | null
  description?: string | null
  category?: { id: number; name: string; code?: string } | null
  recorded_by?: { id: number; name: string } | null
}

export interface ExpenseCategory {
  id: number
  code: string
  name: string
  description?: string | null
  is_recurring: boolean
  is_active: boolean
  sort_order?: number
  expenses_count?: number
}

export interface MembershipPlan {
  id: number
  branch_id: number | null
  code: string
  name: string
  description?: string | null
  duration_value: number
  duration_unit: string
  duration_label?: string
  price: number
  default_discount?: number
  registration_fee?: number
  grace_days?: number
  is_active: boolean
  sort_order?: number
  active_memberships_count?: number
}

/* ---------------------------------------------------------------- people */

export interface Membership {
  id: number
  member_id: number
  membership_plan_id: number
  reference: string
  plan_name: string
  start_date: string
  end_date: string
  access_until?: string | null
  days_remaining: number
  is_expired: boolean
  price: number
  discount?: number
  registration_fee?: number
  final_amount: number
  paid_amount: number
  balance: number
  payment_status: string
  status: string
  status_label?: string
  notes?: string | null
  member?: EmbeddedMember | null
}

export interface Lead {
  id: number
  name: string
  telephone?: string | null
  email?: string | null
  message?: string | null
  plan?: string | null
  source?: string | null
  status: string
  notes?: string | null
  contacted_at?: string | null
  handled_by?: string | null
  converted_member?: { id: number; full_name: string; membership_number: string } | null
  created_at: string | null
}

export interface Trainer {
  id: number
  branch_id: number | null
  trainer_code: string
  full_name: string
  telephone?: string | null
  email?: string | null
  specialization?: string | null
  joining_date?: string | null
  status: string
  notes?: string | null
  members_count?: number
  branch?: { id: number; name: string } | null
}

export interface WorkoutPlan {
  id: number
  member_id: number
  trainer_id?: number | null
  title: string
  description?: string | null
  goal?: string | null
  status: string
  start_date?: string | null
  end_date?: string | null
  notes?: string | null
  exercises_count?: number
  member?: { id: number; full_name: string; membership_number: string } | null
  trainer?: { id: number; full_name: string } | null
}

/* --------------------------------------------------------------- the gym */

export interface AttendanceRow {
  id: number
  member_id: number
  attendance_date: string
  check_in_time: string | null
  check_out_time: string | null
  duration_minutes: number | null
  attendance_type: string
  verification_type?: string
  status: string
  status_label?: string
  membership_valid?: boolean
  validation_message?: string | null
  notes?: string | null
  member?: EmbeddedMember | null
}

export interface Device {
  id: number
  branch_id: number | null
  code: string
  name: string
  driver?: string | null
  model?: string | null
  serial_number?: string | null
  ip_address?: string | null
  port?: number | null
  mode?: string | null
  mode_label?: string | null
  sync_mode?: string | null
  is_active: boolean
  connection_status?: string | null
  last_error?: string | null
  last_seen_at?: string | null
  last_sync_at?: string | null
  enrolled_users_count?: number
}

/* -------------------------------------------------------- administration */

export interface StaffAccount {
  id: number
  name: string
  email: string
  phone?: string | null
  branch_id: number | null
  branch?: { id: number; name: string } | null
  is_active: boolean
  roles: string[]
  last_login_at?: string | null
}

export interface Role {
  id: number
  name: string
  permissions: string[]
  users_count?: number
}

export interface Branch {
  id: number
  code: string
  name: string
  address?: string | null
  city?: string | null
  telephone?: string | null
  email?: string | null
  is_active: boolean
  is_default?: boolean
  members_count?: number
  devices_count?: number
}

export interface AuditEntry {
  id: number
  user_id: number | null
  user_name?: string | null
  user_role?: string | null
  event: string
  record_type: string
  record_id: number | null
  description?: string | null
  ip_address?: string | null
  method?: string | null
  created_at: string
}

export interface SettingRow {
  id: number
  branch_id: number | null
  group: string
  key: string
  value: string | number | boolean | null
  type: 'string' | 'integer' | 'decimal' | 'boolean' | 'json'
  label: string | null
  description: string | null
  is_override: boolean
}

export interface BackupRow {
  id: number
  type: string
  filename: string
  size_mb?: number | null
  status: string
  message?: string | null
  started_at: string | null
  finished_at: string | null
  downloadable: boolean
}

export interface SessionRow {
  id: number
  name: string
  ip_address?: string | null
  device?: string | null
  created_at: string
  last_used_at: string | null
  expires_at?: string | null
  is_current: boolean
}
