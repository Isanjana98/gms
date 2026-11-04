/** Shapes returned by the member API. */

export interface MembershipSummary {
  reference: string
  plan_name: string
  start_date: string | null
  end_date: string | null
  days_remaining: number
  is_expired: boolean
  status: string
  balance: number
}

export interface MemberProfile {
  id: number
  full_name: string
  membership_number: string
  telephone: string | null
  email: string | null
  photo_url: string | null
  status: string
  join_date: string | null
  total_visits: number
  last_visit_at: string | null
  branch: string | null
  membership: MembershipSummary | null
}

export interface Visit {
  id: number
  date: string | null
  check_in_time: string | null
  check_out_time: string | null
  duration_minutes: number | null
  status: string
}

export interface AttendanceSummary {
  total_visits: number
  this_month: number
  avg_duration_minutes: number | null
  last_visit: string | null
}

export interface PaymentRow {
  id: number
  receipt_number: string
  paid_at: string | null
  amount: number
  method: string | null
  payment_type: string | null
  is_refund: boolean
  status: string
}

export interface TimetableEntry {
  id: number
  day_of_week: number
  day_name: string
  class_name: string
  start_time: string
  end_time: string
  time_label: string
  room: string | null
  instructor: string | null
}

export interface GymDetails {
  name: string
  tagline: string | null
  logo_url: string | null
  opening_hours: Array<{ days?: string; hours?: string }>
  branch: {
    name: string
    address: string | null
    city: string | null
    telephone: string | null
    email: string | null
  } | null
}

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number }
}
