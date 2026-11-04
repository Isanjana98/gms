import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { BrandingManager } from '../components/BrandingManager'
import { DataReset } from '../components/DataReset'
import { Alert, Button, Card, Field, FileButton, Input, LoadingBlock, PageHeader, Select } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { dateTime } from '../lib/format'

interface Setting {
  id: number
  branch_id: number | null
  group: string
  key: string
  value: unknown
  type: string
  label: string | null
  description: string | null
}

interface BackupRow {
  id: number
  type: string
  filename: string
  size_mb: number | null
  status: string
  message: string | null
  started_at: string | null
  downloadable: boolean
}

type SettingsTab = 'rules' | 'branding' | 'reset'

/**
 * Separate tabs, because these are three different jobs: adjusting a rule,
 * changing what members see, and destroying data. Putting the reset controls
 * at the bottom of the same scroll as the attendance window is how somebody
 * ends up there by accident.
 */
function SettingsTabs({
  tab,
  setTab,
  canBrand,
  canReset,
}: {
  tab: SettingsTab
  setTab: (tab: SettingsTab) => void
  canBrand: boolean
  canReset: boolean
}) {
  const tabs = [
    ['rules', 'Rules & backups'],
    ...(canBrand ? [['branding', 'Branding']] : []),
    ...(canReset ? [['reset', 'Reset data']] : []),
  ] as Array<[SettingsTab, string]>

  if (tabs.length === 1) return null

  return (
    <div className="mb-5 flex flex-wrap gap-1 rounded-lg border border-ink-200 bg-white p-1">
      {tabs.map(([key, label]) => (
        <button
          key={key}
          type="button"
          onClick={() => setTab(key)}
          className={`rounded px-3 py-1.5 text-sm font-medium transition ${
            tab === key
              ? key === 'reset'
                ? 'bg-red-600 text-white'
                : 'bg-brand-600 text-white'
              : 'text-ink-600 hover:bg-ink-100'
          }`}
        >
          {label}
        </button>
      ))}
    </div>
  )
}

/** SRS 10 / 23 / 30 - Administrator settings and backups. */
export function SettingsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()

  // Only the fields the administrator has actually touched are held in state.
  // Copying the whole server response into state would mean a background
  // refetch either clobbers an edit in progress or silently goes stale; this
  // way the server value is the default and an edit is an explicit override.
  const [edits, setEdits] = useState<Record<string, string>>({})
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null)
  const [tab, setTab] = useState<SettingsTab>('rules')

  const { data, isLoading, error } = useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await api.get<{ settings: Setting[] }>('/settings')).data,
  })

  const valueFor = (setting: Setting) => edits[setting.key] ?? String(setting.value ?? '')
  const dirty = Object.keys(edits).length > 0

  const save = useMutation({
    mutationFn: async () => {
      const payload = (data?.settings ?? [])
        .filter((setting) => setting.key in edits)
        .map((setting) => ({
          key: setting.key,
          value: setting.type === 'boolean' ? edits[setting.key] === 'true' : edits[setting.key],
          type: setting.type,
        }))

      return (await api.put('/settings', { settings: payload })).data
    },
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Settings saved.' })
      setEdits({})
      queryClient.invalidateQueries({ queryKey: ['settings'] })
    },
    onError: (err) => setNotice({ tone: 'danger', text: errorMessage(err) }),
  })

  if (isLoading) return <LoadingBlock />
  if (error) return <Alert>{errorMessage(error)}</Alert>

  const groups = (data?.settings ?? []).reduce<Record<string, Setting[]>>((acc, setting) => {
    ;(acc[setting.group] ??= []).push(setting)
    return acc
  }, {})

  const tabs = (
    <SettingsTabs tab={tab} setTab={setTab} canBrand={can(P.brandingManage)} canReset={can(P.dataReset)} />
  )

  if (tab === 'branding') {
    return (
      <>
        <PageHeader title="Settings" subtitle="Rules and branding an administrator can change without a deployment" />
        {tabs}
        <BrandingManager />
      </>
    )
  }

  if (tab === 'reset') {
    return (
      <>
        <PageHeader title="Settings" subtitle="Clear operational data when handing the system over" />
        {tabs}
        <DataReset />
      </>
    )
  }

  return (
    <>
      <PageHeader
        title="Settings"
        subtitle="Rules an administrator can change without a deployment"
        actions={
          <Button loading={save.isPending} disabled={!dirty} onClick={() => save.mutate()}>
            {dirty ? `Save ${Object.keys(edits).length} change${Object.keys(edits).length === 1 ? '' : 's'}` : 'Saved'}
          </Button>
        }
      />

      {tabs}

      {notice && (
        <div className="mb-5">
          <Alert tone={notice.tone}>{notice.text}</Alert>
        </div>
      )}

      <div className="space-y-6">
        {Object.entries(groups).map(([group, settings]) => (
          <Card key={group} title={group.charAt(0).toUpperCase() + group.slice(1)}>
            <div className="space-y-5">
              {settings.map((setting) => (
                <Field key={setting.key} label={setting.label ?? setting.key} hint={setting.description ?? undefined}>
                  {setting.type === 'boolean' ? (
                    <Select
                      value={valueFor(setting) === 'true' || valueFor(setting) === '1' ? 'true' : 'false'}
                      onChange={(e) => setEdits((prev) => ({ ...prev, [setting.key]: e.target.value }))}
                    >
                      <option value="true">On</option>
                      <option value="false">Off</option>
                    </Select>
                  ) : (
                    <Input
                      type={setting.type === 'integer' || setting.type === 'decimal' ? 'number' : 'text'}
                      value={valueFor(setting)}
                      onChange={(e) => setEdits((prev) => ({ ...prev, [setting.key]: e.target.value }))}
                      className="max-w-xs"
                    />
                  )}
                </Field>
              ))}
            </div>
          </Card>
        ))}

        {can(P.backupsManage) && <BackupsCard />}
      </div>
    </>
  )
}

/** SRS 30 - Backups. */
function BackupsCard() {
  const queryClient = useQueryClient()
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['backups'],
    queryFn: async () => (await api.get<{ data: BackupRow[] }>('/backups')).data.data,
  })

  const run = useMutation({
    mutationFn: async () => (await api.post('/backups')).data,
    onSuccess: (result: { message: string }) => {
      setNotice({ tone: 'success', text: result.message })
      queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
    onError: (err) => setNotice({ tone: 'danger', text: errorMessage(err) }),
  })

  return (
    <Card
      title="Database backups"
      subtitle="Daily and weekly backups run automatically"
      actions={
        <Button size="sm" variant="secondary" loading={run.isPending} onClick={() => run.mutate()}>
          Back up now
        </Button>
      }
      padded={false}
    >
      {notice && (
        <div className="border-b border-ink-200 p-4">
          <Alert tone={notice.tone}>{notice.text}</Alert>
        </div>
      )}

      {isLoading ? (
        <LoadingBlock />
      ) : !data?.length ? (
        <p className="px-5 py-8 text-center text-sm text-ink-500">No backups have run yet.</p>
      ) : (
        <ul className="max-h-80 divide-y divide-ink-100 overflow-y-auto">
          {data.slice(0, 15).map((backup) => (
            <li key={backup.id} className="flex items-center justify-between gap-3 px-5 py-2.5">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{backup.filename}</p>
                <p className="text-xs text-ink-500">
                  {dateTime(backup.started_at)} · {backup.type.toLowerCase()}
                  {backup.size_mb ? ` · ${backup.size_mb} MB` : ''}
                </p>
                {backup.status === 'FAILED' && backup.message && (
                  <p className="mt-0.5 text-xs text-red-600">{backup.message}</p>
                )}
              </div>
              {backup.downloadable && (
                <FileButton
                  url={`/backups/${backup.id}/download`}
                  filename={backup.filename}
                  mode="download"
                  variant="ghost"
                  onError={(text) => setNotice({ tone: 'danger', text })}
                >
                  Download
                </FileButton>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
