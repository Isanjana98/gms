import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { ResourceList, Row } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { FormRow, FormSection, NotPermitted, Outcome, SelectField, SwitchField, TextField } from '../../src/components/form'
import { Button, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { fieldErrors, saveError, useAction, useSaveResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'

type Testimonial = {
  id: number
  name: string
  role?: string | null
  quote: string
  rating?: number | null
  is_active: boolean
  sort_order?: number
}

const RATINGS = [5, 4, 3, 2, 1].map((n) => ({ label: '★'.repeat(n), value: n }))

/** What members say, on the public website. */
export default function Testimonials() {
  const { can } = useAuth()
  const router = useRouter()

  const [editing, setEditing] = useState<Testimonial | 'new' | null>(null)
  const [name, setName] = useState('')
  const [role, setRole] = useState('')
  const [quote, setQuote] = useState('')
  const [rating, setRating] = useState<string | number>(5)
  const [active, setActive] = useState(true)
  const [removing, setRemoving] = useState<Testimonial | null>(null)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const list = useQuery({
    queryKey: ['/website/testimonials'],
    enabled: can(P.websiteManage),
    queryFn: async () => {
      const { data } = await api.get<{ data: Testimonial[] } | Testimonial[]>('/website/testimonials')
      return Array.isArray(data) ? data : data.data
    },
  })

  const existing = editing && editing !== 'new' ? editing : undefined
  const save = useSaveResource('/website/testimonials', { id: existing?.id })
  const remove = useAction({ invalidate: ['/website/testimonials'] })
  const errors = fieldErrors(save.error)

  if (!can(P.websiteManage)) {
    return (
      <Screen title="Testimonials" onBack={() => router.back()}>
        <NotPermitted what="manage the website" />
      </Screen>
    )
  }

  const open = (row: Testimonial | 'new') => {
    setResult(null)
    setEditing(row)
    setName(row === 'new' ? '' : row.name)
    setRole(row === 'new' ? '' : (row.role ?? ''))
    setQuote(row === 'new' ? '' : row.quote)
    setRating(row === 'new' ? 5 : (row.rating ?? 5))
    setActive(row === 'new' ? true : row.is_active)
  }

  if (editing) {
    return (
      <Screen
        title={existing ? 'Edit testimonial' : 'New testimonial'}
        onBack={() => setEditing(null)}
      >
        <FormSection title="What they said">
          <FormRow label="Name" error={errors.name}>
            <TextField testID="testimonial-name" value={name} onChange={setName} autoCapitalize="words" />
          </FormRow>
          <FormRow label="Who they are" hint="Shown under the name, e.g. “Member since 2019”." error={errors.role}>
            <TextField value={role} onChange={setRole} />
          </FormRow>
          <FormRow label="Quote" error={errors.quote}>
            <TextField value={quote} onChange={setQuote} multiline />
          </FormRow>
          <FormRow label="Rating" error={errors.rating}>
            <SelectField value={rating} options={RATINGS} onChange={setRating} />
          </FormRow>
          <SwitchField label="Shown on the site" value={active} onChange={setActive} />
        </FormSection>

        {result ? <Outcome ok={result.ok} message={result.message} /> : null}

        <Button
          label={existing ? 'Save changes' : 'Add testimonial'}
          loading={save.isPending}
          onPress={() => {
            setResult(null)
            save.mutate(
              {
                name: name.trim(),
                role: role.trim() || undefined,
                quote: quote.trim(),
                rating: Number(rating),
                is_active: active,
              },
              {
                onSuccess: () => {
                  setEditing(null)
                  void list.refetch()
                },
                onError: (error) => setResult({ ok: false, message: saveError(error) }),
              },
            )
          }}
        />

        {existing ? (
          <Button label="Delete this testimonial" variant="ghost" onPress={() => setRemoving(existing)} />
        ) : null}

        <Muted>Use somebody&apos;s words with their permission. A made-up quote is worse than none.</Muted>

        <ConfirmSheet
          visible={Boolean(removing)}
          title="Delete this testimonial?"
          message="It comes off the website immediately."
          confirmLabel="Delete"
          onCancel={() => setRemoving(null)}
          onConfirm={() => {
            const target = removing
            setRemoving(null)
            if (!target) return

            remove.mutate(
              { url: `/website/testimonials/${target.id}`, method: 'delete' },
              {
                onSuccess: () => {
                  setEditing(null)
                  void list.refetch()
                },
                onError: (error) => setResult({ ok: false, message: saveError(error) }),
              },
            )
          }}
        />
      </Screen>
    )
  }

  return (
    <Screen
      title="Testimonials"
      subtitle={`${list.data?.length ?? 0} collected`}
      onBack={() => router.back()}
      onRefresh={() => void list.refetch()}
      refreshing={list.isRefetching}
    >
      <Button label="Add a testimonial" onPress={() => open('new')} />

      <ResourceList
        rows={list.data ?? []}
        loading={list.isLoading}
        error={list.error ? saveError(list.error) : undefined}
        onRetry={() => void list.refetch()}
        emptyTitle="No testimonials"
        emptyDescription="Nobody has been quoted yet."
        onSelect={open}
        renderRow={(row) => (
          <Row
            title={row.name}
            subtitle={row.quote}
            trailing={row.rating ? '★'.repeat(row.rating) : undefined}
            status={row.is_active ? undefined : 'hidden'}
            statusTone={theme.warn}
          />
        )}
      />

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}
    </Screen>
  )
}
