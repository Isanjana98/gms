import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { ResourceList, Row } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { NotPermitted, Outcome } from '../../src/components/form'
import { Button, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { openDocument } from '../../src/lib/documents'
import { formatDate, formatTime } from '../../src/lib/format'
import { saveError, useAction } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { BackupRow } from '../../src/lib/types'

/**
 * Copies of the database.
 *
 * Taking one from a phone is reasonable — it is a button and a wait. Restoring
 * one is not, and is deliberately absent: putting a database back is done with
 * the server in front of you, not at a bus stop.
 */
export default function Backups() {
  const { can } = useAuth()
  const router = useRouter()

  const [confirming, setConfirming] = useState(false)
  const [downloading, setDownloading] = useState<number | null>(null)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const backups = useQuery({
    queryKey: ['/backups'],
    queryFn: async () => {
      const { data } = await api.get<{ data: BackupRow[] } | BackupRow[]>('/backups')
      return Array.isArray(data) ? data : data.data
    },
  })

  const take = useAction({ invalidate: ['/backups'] })

  if (!can(P.backupsManage)) {
    return (
      <Screen title="Backups" onBack={() => router.back()}>
        <NotPermitted what="manage backups" />
      </Screen>
    )
  }

  return (
    <Screen
      title="Backups"
      subtitle={`${backups.data?.length ?? 0} kept`}
      onBack={() => router.back()}
      onRefresh={() => void backups.refetch()}
      refreshing={backups.isRefetching}
    >
      <Button label="Take a backup now" onPress={() => setConfirming(true)} loading={take.isPending} />

      <ResourceList
        rows={backups.data ?? []}
        loading={backups.isLoading}
        error={backups.error ? saveError(backups.error) : undefined}
        onRetry={() => void backups.refetch()}
        emptyTitle="No backups yet"
        emptyDescription="Take the first one."
        onSelect={(row) => {
          if (!row.downloadable) {
            setResult({ ok: false, message: 'That backup did not finish, so there is nothing to download.' })
            return
          }

          setResult(null)
          setDownloading(row.id)

          openDocument(`/backups/${row.id}/download`, row.filename, 'application/sql')
            .then(() => setResult({ ok: true, message: `${row.filename} downloaded.` }))
            .catch((error) => setResult({ ok: false, message: saveError(error) }))
            .finally(() => setDownloading(null))
        }}
        renderRow={(row) => (
          <Row
            title={row.filename}
            subtitle={`${row.type} · ${formatDate(row.started_at)} ${formatTime(row.started_at)}${
              row.message ? ` · ${row.message}` : ''
            }`}
            trailing={
              downloading === row.id
                ? 'Downloading…'
                : row.size_mb != null
                  ? `${row.size_mb} MB`
                  : undefined
            }
            status={row.status}
            statusTone={row.status === 'SUCCESS' ? theme.good : theme.bad}
          />
        )}
      />

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Muted>
        A backup is a plain SQL dump. Keep one somewhere that is not this building — a copy that burns with the
        server is not a backup.
      </Muted>

      <ConfirmSheet
        visible={confirming}
        title="Take a backup now?"
        message="The database is dumped to the server. On a large gym this takes a minute and slows the system while it runs."
        confirmLabel="Take it"
        onCancel={() => setConfirming(false)}
        onConfirm={() => {
          setConfirming(false)
          setResult(null)
          take.mutate(
            { url: '/backups', payload: {} },
            {
              onSuccess: () => {
                setResult({ ok: true, message: 'Backup taken.' })
                void backups.refetch()
              },
              onError: (error) => setResult({ ok: false, message: saveError(error) }),
            },
          )
        }}
      />
    </Screen>
  )
}
