import { useRouter } from 'expo-router'
import { ResourceList, Row, SearchBar } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted } from '../../src/components/form'
import { P, useAuth } from '../../src/lib/auth'
import { duration, formatDate, formatTime } from '../../src/lib/format'
import { saveError, useSearchableList } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { AttendanceRow } from '../../src/lib/types'

/** The visit log. */
export default function Attendance() {
  const { can } = useAuth()
  const router = useRouter()

  const list = useSearchableList<AttendanceRow>('/attendance', { perPage: 25 })

  if (!can(P.attendanceView)) {
    return (
      <Screen title="Attendance" onBack={() => router.back()}>
        <NotPermitted what="see the visit log" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Attendance"
      subtitle={`${list.total} visits recorded`}
      onBack={() => router.back()}
      onRefresh={() => void list.query.refetch()}
      refreshing={list.query.isRefetching}
    >
      <SearchBar value={list.term} onChange={list.setTerm} placeholder="Member name or number" />

      <ResourceList
        rows={list.rows}
        loading={list.query.isLoading}
        error={list.query.error ? saveError(list.query.error) : undefined}
        onRetry={() => void list.query.refetch()}
        emptyTitle="No visits"
        emptyDescription="Nothing logged that matches."
        onSelect={can(P.attendanceManage) ? (row) => router.push(`/attendance/${row.id}`) : undefined}
        page={list.page}
        lastPage={list.lastPage}
        total={list.total}
        onPage={list.setPage}
        renderRow={(row) => (
          <Row
            title={row.member?.full_name ?? 'Unmatched scan'}
            subtitle={`${formatDate(row.attendance_date)} · in ${formatTime(row.check_in_time)}${
              row.check_out_time ? ` · out ${formatTime(row.check_out_time)}` : ''
            }`}
            trailing={row.duration_minutes ? duration(row.duration_minutes) : undefined}
            status={row.status_label ?? row.status}
            statusTone={row.status === 'OPEN' ? theme.good : theme.dim}
          />
        )}
      />
    </Screen>
  )
}
