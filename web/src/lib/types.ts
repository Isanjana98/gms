/** Shapes returned by the API (SRS 29). */

export type MemberStatus = 'ACTIVE' | 'INACTIVE' | 'SUSPENDED' | 'EXPIRED' | 'BLACKLISTED'
export type MembershipStatus = 'PENDING' | 'ACTIVE' | 'EXPIRED' | 'SUSPENDED' | 'CANCELLED'
export type SettlementStatus = 'UNPAID' | 'PARTIAL' | 'PAID' | 'REFUNDED'
export type PaymentMethod = 'CASH' | 'CARD' | 'BANK_TRANSFER' | 'ONLINE'
export type AttendanceStatus = 'OPEN' | 'COMPLETED' | 'AUTO_CLOSED' | 'CANCELLED'

export interface Branch {
  id: number
  code: string
  name: string
  city?: string | null
  telephone?: string | null
  is_active: boolean
  is_default: boolean
  members_count?: number
  devices_count?: number
}

export interface AuthUser {
  id: number
  name: string
  email: string
  phone?: string | null
  branch_id: number | null
  branch: { id: number; name: string } | null
  is_multi_branch: boolean
  roles: string[]
  permissions: string[]
  last_login_at?: string | null
}

export interface MembershipPlan {
  id: number
  code: string
  name: string
  description?: string | null
  duration_value: number
  duration_unit: 'DAY' | 'WEEK' | 'MONTH' | 'YEAR'
  duration_label: string
  price: number
  default_discount: number
  registration_fee: number
  grace_days: number
  is_active: boolean
  sort_order: number
  active_memberships_count?: number
}

export interface Membership {
  id: number
  member_id: number
  membership_plan_id: number
  renewed_from_id: number | null
  reference: string
  plan_name: string
  start_date: string
  end_date: string
  access_until: string
  days_remaining: number
  is_expired: boolean
  price: number
  discount: number
  registration_fee: number
  final_amount: number
  paid_amount: number
  balance: number
  payment_status: SettlementStatus
  status: MembershipStatus
  status_label: string
  notes?: string | null
  member?: MemberSummary
}

export interface MemberSummary {
  id: number
  membership_number: string
  full_name: string
  telephone?: string | null
  email?: string | null
  status?: MemberStatus
  photo_url?: string | null
  membership_expires_on?: string | null
}

export interface Member extends MemberSummary {
  branch_id: number
  trainer_id: number | null
  nic_passport?: string | null
  date_of_birth?: string | null
  age?: number | null
  gender?: 'MALE' | 'FEMALE' | 'OTHER' | null
  address?: string | null
  city?: string | null
  emergency_contact_name?: string | null
  emergency_contact_number?: string | null
  join_date: string
  status: MemberStatus
  status_label: string
  notes?: string | null
  last_visit_at?: string | null
  total_visits: number
  current_membership?: Membership | null
  memberships?: Membership[]
  biometric_users?: BiometricUser[]
  branch?: Branch
  trainer?: Trainer | null
}

export interface Payment {
  id: number
  member_id: number
  membership_id: number | null
  invoice_id: number | null
  refund_of_payment_id: number | null
  receipt_number: string
  payment_type: string
  payment_type_label: string
  amount: number
  method: PaymentMethod
  method_label: string
  reference?: string | null
  paid_at: string
  status: string
  is_refund: boolean
  notes?: string | null
  member?: MemberSummary
  received_by?: { id: number; name: string } | null
}

export interface ExpenseCategory {
  id: number
  code: string
  name: string
  description?: string | null
  is_recurring: boolean
  is_active: boolean
  sort_order: number
  expenses_count?: number
}

export interface Expense {
  id: number
  branch_id: number
  expense_category_id: number
  reference: string
  expense_date: string
  amount: number
  tax_amount: number
  /** amount + tax, which is what the reports total. */
  total: number
  method: PaymentMethod
  method_label: string
  payee?: string | null
  supplier_reference?: string | null
  description?: string | null
  category?: { id: number; name: string; code: string; is_recurring: boolean }
  recorded_by?: { id: number; name: string } | null
}

export interface Invoice {
  id: number
  member_id: number
  membership_id: number | null
  invoice_number: string
  issue_date: string
  due_date?: string | null
  sub_total: number
  discount: number
  tax: number
  total: number
  paid_amount: number
  balance: number
  status: string
  member?: MemberSummary
  items?: InvoiceItem[]
}

export interface InvoiceItem {
  id: number
  item_type: string
  description: string
  quantity: number
  unit_price: number
  discount: number
  line_total: number
}

export interface Attendance {
  id: number
  member_id: number
  attendance_date: string
  check_in_time: string | null
  check_out_time: string | null
  duration_minutes: number | null
  attendance_type: string
  verification_type: string
  status: AttendanceStatus
  status_label: string
  membership_valid: boolean
  validation_message?: string | null
  notes?: string | null
  member?: MemberSummary
  check_in_device?: BiometricDevice | null
}

export interface BiometricDevice {
  id: number
  branch_id: number
  code: string
  name: string
  driver: string
  model?: string | null
  serial_number?: string | null
  firmware?: string | null
  ip_address: string
  port: number
  use_https: boolean
  username?: string | null
  has_password: boolean
  mode: 'AUTO' | 'CHECK_IN' | 'CHECK_OUT' | null
  mode_label?: string | null
  sync_mode: 'PULL' | 'PUSH' | 'BOTH'
  is_active: boolean
  connection_status: 'UNKNOWN' | 'ONLINE' | 'OFFLINE' | 'ERROR'
  last_error?: string | null
  last_seen_at?: string | null
  last_sync_at?: string | null
  last_event_at?: string | null
  consecutive_failures: number
  enrolled_users_count?: number
  notes?: string | null
}

export interface BiometricUser {
  id: number
  member_id: number | null
  device_id: number
  biometric_user_id: string
  device_user_name?: string | null
  enrollment_status: 'PENDING' | 'ENROLLED' | 'FAILED' | 'REMOVED'
  fingerprint_count: number
  has_card: boolean
  has_face: boolean
  is_verified: boolean
  sync_status: 'PENDING' | 'SYNCED' | 'FAILED'
  sync_error?: string | null
  verified_at?: string | null
  is_active: boolean
  /** Whether the reader will currently admit them, as last pushed to it. */
  access_enabled?: boolean
  member?: MemberSummary
  device?: BiometricDevice
}

export interface Trainer {
  id: number
  branch_id: number
  trainer_code: string
  full_name: string
  telephone?: string | null
  email?: string | null
  specialization?: string | null
  joining_date?: string | null
  status: 'ACTIVE' | 'INACTIVE'
  members_count?: number
}

export interface DashboardSummary {
  generated_at: string
  members: { total: number; active: number; expired: number; new_this_month: number; new_today: number }
  attendance: { today: number; currently_in: number; yesterday: number }
  revenue: { today: number; this_month: number; outstanding: number }
  memberships: { active: number; pending_payment: number; expiring_soon: number }
}

export interface DashboardPayload {
  summary: DashboardSummary
  recent_check_ins: Array<{
    id: number
    member_id: number
    member_name: string
    membership_number: string
    check_in_time: string | null
    check_out_time: string | null
    status: AttendanceStatus
    membership_valid: boolean
  }>
  attendance_trend: Array<{ date: string; count: number }>
  top_members: Array<{ member_id: number; member_name: string; membership_number: string; visits: number }>
  expiring_soon: Array<{
    membership_id: number
    member_id: number
    member_name: string
    membership_number: string
    telephone?: string | null
    plan_name: string
    end_date: string
    days_remaining: number
  }>
}

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; per_page: number; total: number }
  current_page?: number
  last_page?: number
  per_page?: number
  total?: number
}

export interface EntryValidation {
  valid: boolean
  message: string
  currently_checked_in: boolean
  checked_in_at: string | null
  member: { id: number; full_name: string; membership_number: string; status: MemberStatus }
  membership: { reference: string; plan_name: string; end_date: string; days_remaining: number } | null
}

export interface SyncStatus {
  sync_enabled: boolean
  interval_minutes: number
  healthy: boolean
  devices: Array<{
    device_id: number
    name: string
    code: string
    ip_address: string
    mode: string
    sync_mode: string
    connection_status: string
    last_sync_at: string | null
    last_event_at: string | null
    last_error: string | null
    consecutive_failures: number
    is_stale: boolean
  }>
}
