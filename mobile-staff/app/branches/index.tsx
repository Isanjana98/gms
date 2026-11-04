import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { ResourceList, Row } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { FormRow, FormSection, NotPermitted, Outcome, SwitchField, TextField } from '../../src/components/form'
import { Button, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { fieldErrors, saveError, useSaveResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'
import type { Branch } from '../../src/lib/types'

/**
 * The locations this system covers.
 *
 * Small enough to list and add on one screen — most gyms have one branch and
 * will never open this. Pushing a separate form screen for a four-field record
 * would be more navigation than content.
 */
export default function Branches() {
  const { can } = useAuth()
  const router = useRouter()

  const [adding, setAdding] = useState(false)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [city, setCity] = useState('')
  const [telephone, setTelephone] = useState('')
  const [active, setActive] = useState(true)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const branches = useQuery({
    queryKey: ['/branches'],
    queryFn: async () => {
      const { data } = await api.get<{ data: Branch[] } | Branch[]>('/branches')
      return Array.isArray(data) ? data : data.data
    },
  })

  const save = useSaveResource('/branches')
  const errors = fieldErrors(save.error)

  if (!can(P.branchesManage)) {
    return (
      <Screen title="Branches" onBack={() => router.back()}>
        <NotPermitted what="manage branches" />
      </Screen>
    )
  }

  const submit = () => {
    setResult(null)
    save.mutate(
      {
        code: code.trim().toUpperCase(),
        name: name.trim(),
        city: city.trim() || undefined,
        telephone: telephone.trim() || undefined,
        is_active: active,
      },
      {
        onSuccess: () => {
          setResult({ ok: true, message: 'Branch added.' })
          setCode('')
          setName('')
          setCity('')
          setTelephone('')
          setAdding(false)
          void branches.refetch()
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <Screen
      title="Branches"
      subtitle={`${branches.data?.length ?? 0} locations`}
      onBack={() => router.back()}
      onRefresh={() => void branches.refetch()}
      refreshing={branches.isRefetching}
    >
      <ResourceList
        rows={branches.data ?? []}
        loading={branches.isLoading}
        error={branches.error ? saveError(branches.error) : undefined}
        onRetry={() => void branches.refetch()}
        emptyTitle="No branches"
        emptyDescription="Add the first one."
        renderRow={(row) => (
          <Row
            title={row.is_default ? `${row.name} (default)` : row.name}
            subtitle={`${row.code}${row.city ? ` · ${row.city}` : ''} · ${row.members_count ?? 0} members`}
            trailing={row.telephone ?? undefined}
            status={row.is_active ? undefined : 'closed'}
            statusTone={theme.warn}
          />
        )}
      />

      {adding ? (
        <FormSection title="New branch">
          <FormRow label="Name" error={errors.name}>
            <TextField testID="branch-name" value={name} onChange={setName} autoCapitalize="words" />
          </FormRow>
          <FormRow label="Code" hint="Short and unique." error={errors.code}>
            <TextField testID="branch-code" value={code} onChange={setCode} autoCapitalize="none" />
          </FormRow>
          <FormRow label="City" error={errors.city}>
            <TextField value={city} onChange={setCity} autoCapitalize="words" />
          </FormRow>
          <FormRow label="Telephone" error={errors.telephone}>
            <TextField value={telephone} onChange={setTelephone} keyboardType="phone-pad" autoCapitalize="none" />
          </FormRow>
          <SwitchField label="Open" value={active} onChange={setActive} />
          <Button label="Add branch" onPress={submit} loading={save.isPending} />
          <Button label="Cancel" variant="ghost" onPress={() => setAdding(false)} />
        </FormSection>
      ) : (
        <Button label="Add a branch" onPress={() => setAdding(true)} />
      )}

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Muted>
        Editing an existing branch, and moving the default, are done in the admin panel: both change which
        records every other screen can see, and that is worth a bigger screen.
      </Muted>
    </Screen>
  )
}
