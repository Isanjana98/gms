import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { ResourceList, Row } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Button, Card, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate, formatTime } from '../../src/lib/format'
import { saveError, useAction } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Device } from '../../src/lib/types'

/**
 * Fingerprint readers.
 *
 * Testing a device and pulling its scans are exactly the jobs worth doing from
 * a phone, because they are done standing next to the reader. Adding one is
 * not: it needs an IP address, a port and a password typed correctly, and that
 * belongs on a keyboard.
 */
export default function Devices() {
  const { can } = useAuth()
  const router = useRouter()

  const [busy, setBusy] = useState<number | 'sync' | null>(null)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const devices = useQuery({
    queryKey: ['/biometric/devices'],
    enabled: can(P.devicesView),
    queryFn: async () => {
      const { data } = await api.get<{ data: Device[] } | Device[]>('/biometric/devices')
      return Array.isArray(data) ? data : data.data
    },
  })

  const act = useAction({ invalidate: ['/biometric/devices', '/attendance'] })

  if (!can(P.devicesView)) {
    return (
      <Screen title="Devices" onBack={() => router.back()}>
        <NotPermitted what="see devices" />
      </Screen>
    )
  }

  const run = (
    key: number | 'sync',
    url: string,
    success: string,
  ) => {
    setResult(null)
    setBusy(key)

    act.mutate(
      { url, payload: {} },
      {
        onSuccess: (data: unknown) => {
          const message = (data as { message?: string })?.message
          setResult({ ok: true, message: message ?? success })
          setBusy(null)
          void devices.refetch()
        },
        onError: (error) => {
          setResult({ ok: false, message: saveError(error) })
          setBusy(null)
        },
      },
    )
  }

  return (
    <Screen
      title="Devices"
      subtitle={`${devices.data?.length ?? 0} readers`}
      onBack={() => router.back()}
      onRefresh={() => void devices.refetch()}
      refreshing={devices.isRefetching}
    >
      {can(P.devicesSync) ? (
        <Button
          label="Pull scans from every device"
          onPress={() => run('sync', '/biometric/sync', 'Devices synced.')}
          loading={busy === 'sync'}
        />
      ) : null}

      <ResourceList
        rows={devices.data ?? []}
        loading={devices.isLoading}
        error={devices.error ? saveError(devices.error) : undefined}
        onRetry={() => void devices.refetch()}
        emptyTitle="No devices"
        emptyDescription="None have been set up. Add one in the admin panel."
        onSelect={
          can(P.devicesManage)
            ? (row) => run(row.id, `/biometric/devices/${row.id}/test`, `${row.name} answered.`)
            : undefined
        }
        renderRow={(row) => (
          <Row
            title={row.name}
            subtitle={`${row.ip_address ?? 'no address'}${row.port ? `:${row.port}` : ''} · ${
              row.enrolled_users_count ?? 0
            } enrolled${row.last_sync_at ? ` · synced ${formatDate(row.last_sync_at)} ${formatTime(row.last_sync_at)}` : ''}`}
            trailing={busy === row.id ? 'Testing…' : undefined}
            status={row.is_active ? (row.connection_status ?? 'unknown') : 'off'}
            statusTone={
              !row.is_active
                ? theme.dim
                : row.connection_status === 'ONLINE'
                  ? theme.good
                  : row.connection_status === 'OFFLINE'
                    ? theme.bad
                    : theme.warn
            }
          />
        )}
      />

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      {devices.data?.some((d) => d.last_error) ? (
        <Card>
          <Muted>
            {devices.data.find((d) => d.last_error)?.name} last reported:{' '}
            {devices.data.find((d) => d.last_error)?.last_error}
          </Muted>
        </Card>
      ) : null}

      {can(P.devicesManage) ? <Muted>Tap a device to test whether it answers.</Muted> : null}
    </Screen>
  )
}
