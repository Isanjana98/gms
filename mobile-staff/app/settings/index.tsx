import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { FormRow, FormSection, NotPermitted, Outcome, SwitchField, TextField } from '../../src/components/form'
import { Button, Card, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { saveError, useAction } from '../../src/lib/resource'
import type { SettingRow } from '../../src/lib/types'

/**
 * How the gym runs.
 *
 * Rendered from the server's own list rather than from a hard-coded form: each
 * row arrives with its label, its description and its type, so a setting added
 * on the server appears here without a new build. It also means this screen
 * cannot invent a setting the server does not have, which a hand-written form
 * eventually does.
 */
export default function Settings() {
  const { can } = useAuth()
  const router = useRouter()

  const [edits, setEdits] = useState<Record<string, string | boolean>>({})
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const settings = useQuery({
    queryKey: ['/settings'],
    enabled: can(P.settingsManage),
    queryFn: async () => (await api.get<{ settings: SettingRow[] }>('/settings')).data.settings,
  })

  const save = useAction({ invalidate: ['/settings'] })

  if (!can(P.settingsManage)) {
    return (
      <Screen title="Settings" onBack={() => router.back()}>
        <NotPermitted what="change settings" />
      </Screen>
    )
  }

  const rows = settings.data ?? []
  const groups = [...new Set(rows.map((r) => r.group))]
  const changed = Object.keys(edits).length

  const valueOf = (row: SettingRow) => edits[row.key] ?? row.value

  const submit = () => {
    setResult(null)

    save.mutate(
      {
        url: '/settings',
        method: 'put',
        payload: {
          settings: Object.entries(edits).map(([key, value]) => {
            const row = rows.find((r) => r.key === key)
            return { key, value, type: row?.type }
          }),
        },
      },
      {
        onSuccess: () => {
          setResult({ ok: true, message: `${changed} setting${changed === 1 ? '' : 's'} saved.` })
          setEdits({})
          void settings.refetch()
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <Screen
      title="Settings"
      subtitle={`${rows.length} across ${groups.length} groups`}
      onBack={() => router.back()}
      onRefresh={() => void settings.refetch()}
      refreshing={settings.isRefetching}
    >
      {settings.isLoading ? <Muted>Loading…</Muted> : null}
      {settings.error ? <Outcome ok={false} message={saveError(settings.error)} /> : null}

      {groups.map((group) => (
        <FormSection key={group} title={group.charAt(0).toUpperCase() + group.slice(1)}>
          {rows
            .filter((row) => row.group === group)
            .map((row) =>
              row.type === 'boolean' ? (
                <SwitchField
                  key={row.key}
                  label={row.label ?? row.key}
                  hint={row.description ?? undefined}
                  value={Boolean(valueOf(row))}
                  onChange={(v) => setEdits((e) => ({ ...e, [row.key]: v }))}
                />
              ) : (
                <FormRow key={row.key} label={row.label ?? row.key} hint={row.description ?? undefined}>
                  <TextField
                    testID={`setting-${row.key}`}
                    value={String(valueOf(row) ?? '')}
                    onChange={(v) => setEdits((e) => ({ ...e, [row.key]: v }))}
                    keyboardType={row.type === 'integer' || row.type === 'decimal' ? 'numeric' : 'default'}
                    autoCapitalize="none"
                    multiline={row.type === 'json'}
                  />
                </FormRow>
              ),
            )}
        </FormSection>
      ))}

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      {changed > 0 ? (
        <View style={{ gap: 10 }}>
          <Button
            label={`Save ${changed} change${changed === 1 ? '' : 's'}`}
            onPress={submit}
            loading={save.isPending}
          />
          <Button label="Discard changes" variant="ghost" onPress={() => setEdits({})} />
        </View>
      ) : (
        <Card>
          <Muted>Nothing changed yet. Edit a setting above and a save button appears here.</Muted>
        </Card>
      )}
    </Screen>
  )
}
