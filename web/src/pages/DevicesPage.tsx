import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  Input,
  LoadingBlock,
  Modal,
  PageHeader,
  Select,
  StatusBadge,
  Table,
  Td,
  Th,
  cx,
} from '../components/ui'
import { useToast } from '../components/Toast'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { dateTime, relative } from '../lib/format'
import type { BiometricDevice, BiometricUser, MemberSummary, Paginated, SyncStatus } from '../lib/types'

interface SyncLog {
  id: number
  device: string | null
  trigger: string
  type: string
  status: string
  records_fetched: number
  records_new: number
  records_duplicate: number
  records_unmatched: number
  records_rejected: number
  records_failed: number
  message: string | null
  started_at: string | null
  duration_ms: number | null
}

export function DevicesPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()

  const [editing, setEditing] = useState<BiometricDevice | null>(null)
  const [creating, setCreating] = useState(false)
  const [linking, setLinking] = useState<BiometricUser | null>(null)

  const devices = useQuery({
    queryKey: ['devices'],
    queryFn: async () => (await api.get<{ data: BiometricDevice[] }>('/biometric/devices?include_inactive=true')).data.data,
  })

  const status = useQuery({
    queryKey: ['sync-status'],
    queryFn: async () => (await api.get<SyncStatus>('/biometric/sync-status')).data,
    refetchInterval: 30_000,
  })

  const logs = useQuery({
    queryKey: ['sync-logs'],
    queryFn: async () => (await api.get<{ data: SyncLog[] }>('/biometric/sync-logs?per_page=10')).data.data,
    refetchInterval: 60_000,
  })

  const unmatched = useQuery({
    queryKey: ['unmatched-users'],
    queryFn: async () =>
      (await api.get<Paginated<BiometricUser>>('/biometric/users?unmatched=true&per_page=20')).data,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['devices'] })
    queryClient.invalidateQueries({ queryKey: ['sync-status'] })
    queryClient.invalidateQueries({ queryKey: ['sync-logs'] })
    queryClient.invalidateQueries({ queryKey: ['unmatched-users'] })
  }

  const test = useMutation({
    mutationFn: async (deviceId: number) => (await api.post(`/biometric/devices/${deviceId}/test`)).data,
    onSuccess: (data: { message: string }) => {
      toast.success(data.message)
      refresh()
    },
    onError: (error) => {
      toast.error(errorMessage(error))
      refresh()
    },
  })

  const sync = useMutation({
    mutationFn: async (deviceId?: number) =>
      (await api.post('/biometric/sync', deviceId ? { device_id: deviceId } : {})).data,
    onSuccess: (data: { message: string }) => {
      toast.success(data.message)
      refresh()
    },
    onError: (error) => toast.error(errorMessage(error)),
  })

  const syncUsers = useMutation({
    mutationFn: async ({ deviceId, direction }: { deviceId: number; direction: 'up' | 'down' }) =>
      (await api.post('/biometric/users/sync', { device_id: deviceId, direction })).data,
    onSuccess: (data: { message: string }) => {
      toast.success(data.message)
      refresh()
    },
    onError: (error) => toast.error(errorMessage(error)),
  })

  const openDoor = useMutation({
    mutationFn: async ({ deviceId, reason }: { deviceId: number; reason: string }) =>
      (await api.post(`/biometric/devices/${deviceId}/open-door`, { reason })).data,
    onSuccess: (data: { message: string }) => toast.success(data.message),
    onError: (error) => toast.error(errorMessage(error)),
  })

  const canManage = can(P.devicesManage)
  const canSync = can(P.devicesSync)
  const canOpenDoor = can(P.devicesOpenDoor)
  const canEnrol = can(P.enrollmentManage)

  return (
    <>
      <PageHeader
        title="Fingerprint devices"
        subtitle="Readers, synchronisation and unmatched device users"
        actions={
          <>
            {canSync && (
              <Button variant="secondary" loading={sync.isPending} onClick={() => sync.mutate(undefined)}>
                Sync attendance now
              </Button>
            )}
            {canManage && <Button onClick={() => setCreating(true)}>Add device</Button>}
          </>
        }
      />

      {status.data && !status.data.sync_enabled && (
        <div className="mb-5">
          <Alert tone="warning">
            Automatic synchronisation is switched off. Attendance will only arrive when someone presses “Sync
            attendance now”, or when a device pushes an event.
          </Alert>
        </div>
      )}

      {status.data?.devices?.some((d) => d.is_stale) && (
        <div className="mb-5">
          <Alert tone="warning">
            One or more readers have not synchronised recently. Check that the scheduler is running
            (<code className="rounded bg-amber-100 px-1">php artisan schedule:work</code>) and that the devices are
            reachable.
          </Alert>
        </div>
      )}

      <Card title="Devices" padded={false}>
        {devices.isLoading ? (
          <LoadingBlock />
        ) : !devices.data?.length ? (
          <EmptyState
            title="No readers configured"
            description="Add the fingerprint terminal at your entrance to start collecting attendance."
            action={canManage && <Button onClick={() => setCreating(true)}>Add device</Button>}
          />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Device</Th>
                <Th>Address</Th>
                <Th>Mode</Th>
                <Th>Connection</Th>
                <Th>Last sync</Th>
                <Th align="right">Actions</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {devices.data.map((device) => {
                const live = status.data?.devices?.find((d) => d.device_id === device.id)

                return (
                  <tr key={device.id} className={cx(!device.is_active && 'opacity-60')}>
                    <Td>
                      <span className="font-medium text-ink-900">{device.name}</span>
                      <span className="block text-xs text-ink-500">
                        {device.code} · {device.driver}
                        {device.model ? ` · ${device.model}` : ''}
                      </span>
                      {!device.is_active && (
                        <span className="mt-1 inline-block">
                          <Badge tone="neutral">inactive</Badge>
                        </span>
                      )}
                    </Td>
                    <Td>
                      <code className="text-xs">
                        {device.ip_address}:{device.port}
                      </code>
                      <span className="block text-xs text-ink-500">
                        {device.enrolled_users_count ?? 0} enrolled
                      </span>
                    </Td>
                    <Td>
                      <span className="text-sm">{device.mode_label ?? device.mode}</span>
                      <span className="block text-xs text-ink-500">{device.sync_mode.toLowerCase()}</span>
                    </Td>
                    <Td>
                      <StatusBadge status={device.connection_status} />
                      {device.last_error && (
                        <span className="mt-1 block max-w-[16rem] text-xs text-red-600">{device.last_error}</span>
                      )}
                    </Td>
                    <Td>
                      <span className="text-sm">{device.last_sync_at ? relative(device.last_sync_at) : 'never'}</span>
                      {live?.is_stale && <span className="block text-xs text-amber-700">overdue</span>}
                    </Td>
                    <Td align="right">
                      <div className="flex flex-wrap justify-end gap-1.5">
                        <Button size="sm" variant="secondary" loading={test.isPending} onClick={() => test.mutate(device.id)}>
                          Test
                        </Button>
                        {canOpenDoor && device.is_active && (
                          // One press releases the lock. There is no
                          // confirmation on purpose: the people who hold this
                          // permission are standing at the door with a member
                          // whose finger will not read, and a dialog in that
                          // moment is a second thing to get past rather than a
                          // safeguard. The audit entry is written either way,
                          // before the reader is even contacted, so a press
                          // that opens nothing is still on the record.
                          <Button
                            size="sm"
                            variant="ghost"
                            loading={openDoor.isPending && openDoor.variables?.deviceId === device.id}
                            onClick={() =>
                              openDoor.mutate({ deviceId: device.id, reason: 'Opened from the devices list' })
                            }
                          >
                            Open door
                          </Button>
                        )}
                        {canSync && (
                          <>
                            <Button size="sm" variant="ghost" onClick={() => sync.mutate(device.id)}>
                              Sync
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => syncUsers.mutate({ deviceId: device.id, direction: 'down' })}
                            >
                              Read users
                            </Button>
                          </>
                        )}
                        {canManage && (
                          <Button size="sm" variant="ghost" onClick={() => setEditing(device)}>
                            Edit
                          </Button>
                        )}
                      </div>
                    </Td>
                  </tr>
                )
              })}
            </tbody>
          </Table>
        )}
      </Card>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <Card
          title="Unmatched device users"
          subtitle="Registered on a reader but not linked to a member"
          padded={false}
        >
          {!unmatched.data?.data?.length ? (
            <EmptyState title="Every device user is matched to a member" />
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>Biometric ID</Th>
                  <Th>Name on device</Th>
                  <Th>Device</Th>
                  <Th align="right" />
                </tr>
              </thead>
              <tbody className="divide-y divide-ink-100">
                {unmatched.data.data.map((user) => (
                  <tr key={user.id}>
                    <Td>
                      <code className="rounded bg-ink-100 px-1.5 py-0.5 text-xs">{user.biometric_user_id}</code>
                    </Td>
                    <Td>{user.device_user_name ?? <span className="text-ink-400">—</span>}</Td>
                    <Td>{user.device?.name ?? `#${user.device_id}`}</Td>
                    <Td align="right">
                      {canEnrol && (
                        <Button size="sm" variant="secondary" onClick={() => setLinking(user)}>
                          Link to member
                        </Button>
                      )}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>

        <Card title="Synchronisation log" subtitle="Most recent runs" padded={false}>
          {!logs.data?.length ? (
            <EmptyState title="No synchronisation runs yet" />
          ) : (
            <ul className="max-h-[22rem] divide-y divide-ink-100 overflow-y-auto">
              {logs.data.map((log) => (
                <li key={log.id} className="px-4 py-2.5">
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-sm font-medium">
                      {log.device ?? 'All devices'}
                      <span className="ml-1.5 text-xs font-normal text-ink-500">
                        {log.type.replace(/_/g, ' ').toLowerCase()}
                      </span>
                    </span>
                    <StatusBadge status={log.status} />
                  </div>
                  <p className="mt-0.5 text-xs text-ink-500">
                    {dateTime(log.started_at)}
                    {log.duration_ms != null ? ` · ${log.duration_ms}ms` : ''}
                  </p>
                  {log.message && <p className="mt-1 text-xs text-ink-600">{log.message}</p>}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {(creating || editing) && (
        <DeviceModal
          device={editing}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
          onSaved={() => {
            setCreating(false)
            setEditing(null)
            refresh()
          }}
        />
      )}

      {linking && (
        <LinkMemberModal
          deviceUser={linking}
          onClose={() => setLinking(null)}
          onLinked={(message) => {
            setLinking(null)
            toast.success(message)
            refresh()
          }}
        />
      )}
    </>
  )
}

/**
 * Attach a device user the reader already knows to a member.
 *
 * The scans this device user has already made are sitting in the event log
 * unusable, so the count recovered is reported back rather than left for
 * somebody to notice on the attendance screen.
 */
function LinkMemberModal({
  deviceUser,
  onClose,
  onLinked,
}: {
  deviceUser: BiometricUser
  onClose: () => void
  onLinked: (message: string) => void
}) {
  const [term, setTerm] = useState('')
  const [debounced, setDebounced] = useState('')
  const [selected, setSelected] = useState<MemberSummary | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(term.trim()), 250)
    return () => clearTimeout(timer)
  }, [term])

  const results = useQuery({
    queryKey: ['link-member-search', debounced],
    queryFn: async () =>
      (await api.get<Paginated<MemberSummary>>('/members', { params: { search: debounced, per_page: 8 } })).data,
    enabled: debounced.length >= 2,
  })

  const link = useMutation({
    mutationFn: async () =>
      (await api.post(`/biometric/users/${deviceUser.id}/match`, { member_id: selected!.id })).data,
    onSuccess: (data: { message: string }) => onLinked(data.message),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={`Link device user ${deviceUser.biometric_user_id}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button disabled={!selected} loading={link.isPending} onClick={() => link.mutate()}>
            Link
          </Button>
        </>
      }
    >
      {error && (
        <div className="mb-4">
          <Alert tone="danger">{error}</Alert>
        </div>
      )}

      <p className="text-sm text-ink-700">
        <code className="rounded bg-ink-100 px-1.5 py-0.5 text-xs">{deviceUser.biometric_user_id}</code>
        {deviceUser.device_user_name ? ` — “${deviceUser.device_user_name}” on the reader` : ''}
        {deviceUser.device?.name ? ` · ${deviceUser.device.name}` : ''}
      </p>

      <div className="mt-4">
        <Field label="Member" hint="Search by name or membership number, e.g. GM-2026-00001.">
          <Input
            value={selected ? `${selected.full_name} · ${selected.membership_number}` : term}
            onChange={(e) => {
              setSelected(null)
              setTerm(e.target.value)
            }}
            placeholder="GM-2026-00001"
            autoFocus
          />
        </Field>
      </div>

      {!selected && debounced.length >= 2 && (
        <div className="mt-2 max-h-56 overflow-y-auto rounded border border-ink-200">
          {results.isFetching && !results.data ? (
            <p className="px-3 py-2 text-sm text-ink-500">Searching…</p>
          ) : !results.data?.data?.length ? (
            <p className="px-3 py-2 text-sm text-ink-500">No member matches “{debounced}”.</p>
          ) : (
            <ul className="divide-y divide-ink-100">
              {results.data.data.map((member) => (
                <li key={member.id}>
                  <button
                    type="button"
                    className="w-full px-3 py-2 text-left text-sm hover:bg-ink-50"
                    onClick={() => setSelected(member)}
                  >
                    <span className="font-medium text-ink-900">{member.full_name}</span>
                    <span className="block text-xs text-ink-500">{member.membership_number}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      <div className="mt-4">
        <Alert tone="info">
          Any scans this device user has already made become this member&apos;s attendance, backdated to when they
          actually happened.
        </Alert>
      </div>
    </Modal>
  )
}

function DeviceModal({
  device,
  onClose,
  onSaved,
}: {
  device: BiometricDevice | null
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState<Record<string, string | boolean>>({
    code: device?.code ?? '',
    name: device?.name ?? '',
    driver: device?.driver ?? 'hikvision',
    ip_address: device?.ip_address ?? '',
    port: String(device?.port ?? 80),
    use_https: device?.use_https ?? false,
    username: device?.username ?? 'admin',
    password: '',
    mode: device?.mode ?? 'AUTO',
    sync_mode: device?.sync_mode ?? 'PULL',
    is_active: device?.is_active ?? true,
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const { data: drivers } = useQuery({
    queryKey: ['drivers'],
    queryFn: async () => (await api.get<{ data: string[] }>('/biometric/drivers')).data.data,
  })

  const save = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = { ...form, port: Number(form.port) }
      // An empty password means "keep the current one" — the interface never
      // receives it back, so it cannot re-submit it.
      if (!payload.password) delete payload.password

      return device
        ? (await api.put(`/biometric/devices/${device.id}`, payload)).data
        : (await api.post('/biometric/devices', payload)).data
    },
    onSuccess: onSaved,
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  const set = (key: string) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const submit = (event: FormEvent) => {
    event.preventDefault()
    save.mutate()
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={device ? `Edit ${device.name}` : 'Add fingerprint device'}
      wide
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button form="device-form" type="submit" loading={save.isPending}>
            {device ? 'Save changes' : 'Add device'}
          </Button>
        </>
      }
    >
      <form id="device-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name" required error={errors.name}>
            <Input value={String(form.name)} onChange={set('name')} placeholder="Main entrance" required />
          </Field>

          <Field
            label="Code"
            required
            error={errors.code}
            // Not "used in the device's push URL" — that was read as an
            // instruction to paste the URL, and a code with slashes in it
            // produces a push endpoint that can never route.
            hint="A short label of your own, e.g. ENT-01. Letters, numbers and dashes."
          >
            <Input value={String(form.code)} onChange={set('code')} placeholder="ENT-01" required />
          </Field>

          <Field label="IP address" required error={errors.ip_address}>
            <Input value={String(form.ip_address)} onChange={set('ip_address')} placeholder="192.168.1.201" required />
          </Field>

          <Field label="Port" error={errors.port}>
            <Input type="number" value={String(form.port)} onChange={set('port')} />
          </Field>

          <Field label="Device username" error={errors.username}>
            <Input value={String(form.username)} onChange={set('username')} autoComplete="off" />
          </Field>

          <Field
            label="Device password"
            error={errors.password}
            hint={device ? 'Leave blank to keep the current password.' : undefined}
          >
            <Input
              type="password"
              value={String(form.password)}
              onChange={set('password')}
              autoComplete="new-password"
            />
          </Field>

          <Field label="Vendor" error={errors.driver}>
            <Select value={String(form.driver)} onChange={set('driver')}>
              {(drivers ?? ['hikvision']).map((driver) => (
                <option key={driver} value={driver}>
                  {driver}
                </option>
              ))}
            </Select>
          </Field>

          <Field
            label="Operating mode"
            hint="Automatic: first scan in, next scan out."
          >
            <Select value={String(form.mode)} onChange={set('mode')}>
              <option value="AUTO">Automatic (single device)</option>
              <option value="CHECK_IN">Check-in only</option>
              <option value="CHECK_OUT">Check-out only</option>
            </Select>
          </Field>

          <Field label="Synchronisation" hint="Pull is polled on a timer; push is sent by the device.">
            <Select value={String(form.sync_mode)} onChange={set('sync_mode')}>
              <option value="PULL">Pull (recommended)</option>
              <option value="PUSH">Push only</option>
              <option value="BOTH">Both</option>
            </Select>
          </Field>

          <label className="flex items-center gap-2 self-end pb-2 text-sm text-ink-700">
            <input
              type="checkbox"
              checked={Boolean(form.is_active)}
              onChange={(e) => setForm((prev) => ({ ...prev, is_active: e.target.checked }))}
              className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
            />
            Device is active
          </label>
        </div>

        <div className="rounded-lg bg-ink-50 p-3 text-xs text-ink-600">
          After saving, press <strong>Test</strong> to confirm the reader answers. The model, serial number and
          firmware are filled in automatically from the device itself.
        </div>
      </form>
    </Modal>
  )
}
