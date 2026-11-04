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
import type { Trainer } from '../../src/lib/types'

type Entry = {
  id: number
  class_name: string
  description?: string | null
  day_of_week: number
  day_name?: string
  start_time: string
  end_time: string
  room?: string | null
  trainer_id?: number | null
  instructor?: string | null
  is_active: boolean
}

const DAYS = [
  { label: 'Monday', value: 1 },
  { label: 'Tuesday', value: 2 },
  { label: 'Wednesday', value: 3 },
  { label: 'Thursday', value: 4 },
  { label: 'Friday', value: 5 },
  { label: 'Saturday', value: 6 },
  { label: 'Sunday', value: 7 },
]

/** What runs, and when. */
export default function Timetable() {
  const { can } = useAuth()
  const router = useRouter()

  const [editing, setEditing] = useState<Entry | 'new' | null>(null)
  const [className, setClassName] = useState('')
  const [day, setDay] = useState<string | number>(1)
  const [start, setStart] = useState('06:00')
  const [end, setEnd] = useState('07:00')
  const [room, setRoom] = useState('')
  const [trainerId, setTrainerId] = useState<string | number | null>(null)
  const [active, setActive] = useState(true)
  const [removing, setRemoving] = useState<Entry | null>(null)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const entries = useQuery({
    queryKey: ['/website/timetable'],
    enabled: can(P.websiteManage),
    queryFn: async () => {
      const { data } = await api.get<{ data: Entry[] } | Entry[]>('/website/timetable')
      return Array.isArray(data) ? data : data.data
    },
  })

  const trainers = useQuery({
    queryKey: ['/trainers'],
    enabled: Boolean(editing),
    queryFn: async () => (await api.get<{ data: Trainer[] }>('/trainers', { params: { per_page: 100 } })).data.data,
  })

  const existing = editing && editing !== 'new' ? editing : undefined
  const save = useSaveResource('/website/timetable', { id: existing?.id })
  const remove = useAction({ invalidate: ['/website/timetable'] })
  const errors = fieldErrors(save.error)

  if (!can(P.websiteManage)) {
    return (
      <Screen title="Class timetable" onBack={() => router.back()}>
        <NotPermitted what="manage the website" />
      </Screen>
    )
  }

  const open = (row: Entry | 'new') => {
    setResult(null)
    setEditing(row)
    setClassName(row === 'new' ? '' : row.class_name)
    setDay(row === 'new' ? 1 : row.day_of_week)
    setStart(row === 'new' ? '06:00' : row.start_time)
    setEnd(row === 'new' ? '07:00' : row.end_time)
    setRoom(row === 'new' ? '' : (row.room ?? ''))
    setTrainerId(row === 'new' ? null : (row.trainer_id ?? null))
    setActive(row === 'new' ? true : row.is_active)
  }

  if (editing) {
    return (
      <Screen title={existing ? 'Edit class' : 'New class'} onBack={() => setEditing(null)}>
        <FormSection title="The class">
          <FormRow label="Name" error={errors.class_name}>
            <TextField testID="class-name" value={className} onChange={setClassName} />
          </FormRow>
          <FormRow label="Day" error={errors.day_of_week}>
            <SelectField value={day} options={DAYS} onChange={setDay} />
          </FormRow>
          <FormRow label="Starts" hint="24-hour, as HH:MM." error={errors.start_time}>
            <TextField value={start} onChange={setStart} keyboardType="numeric" autoCapitalize="none" />
          </FormRow>
          <FormRow label="Ends" error={errors.end_time}>
            <TextField value={end} onChange={setEnd} keyboardType="numeric" autoCapitalize="none" />
          </FormRow>
          <FormRow label="Room" error={errors.room}>
            <TextField value={room} onChange={setRoom} placeholder="Studio 1" />
          </FormRow>
          <FormRow label="Taken by" error={errors.trainer_id}>
            <SelectField
              value={trainerId}
              options={(trainers.data ?? []).map((t) => ({ label: t.full_name, value: t.id }))}
              onChange={setTrainerId}
              placeholder={trainers.isLoading ? 'Loading…' : 'Nobody set'}
            />
          </FormRow>
          <SwitchField label="Shown on the site" value={active} onChange={setActive} />
        </FormSection>

        {result ? <Outcome ok={result.ok} message={result.message} /> : null}

        <Button
          label={existing ? 'Save changes' : 'Add class'}
          loading={save.isPending}
          onPress={() => {
            setResult(null)
            save.mutate(
              {
                class_name: className.trim(),
                day_of_week: Number(day),
                start_time: start.trim(),
                end_time: end.trim(),
                room: room.trim() || undefined,
                trainer_id: trainerId ?? undefined,
                is_active: active,
              },
              {
                onSuccess: () => {
                  setEditing(null)
                  void entries.refetch()
                },
                onError: (error) => setResult({ ok: false, message: saveError(error) }),
              },
            )
          }}
        />

        {existing ? (
          <Button label="Remove this class" variant="ghost" onPress={() => setRemoving(existing)} />
        ) : null}

        <ConfirmSheet
          visible={Boolean(removing)}
          title="Remove this class?"
          message="It comes off the published timetable immediately."
          confirmLabel="Remove"
          onCancel={() => setRemoving(null)}
          onConfirm={() => {
            const target = removing
            setRemoving(null)
            if (!target) return

            remove.mutate(
              { url: `/website/timetable/${target.id}`, method: 'delete' },
              {
                onSuccess: () => {
                  setEditing(null)
                  void entries.refetch()
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
      title="Class timetable"
      subtitle={`${entries.data?.length ?? 0} classes`}
      onBack={() => router.back()}
      onRefresh={() => void entries.refetch()}
      refreshing={entries.isRefetching}
    >
      <Button label="Add a class" onPress={() => open('new')} />

      <ResourceList
        rows={entries.data ?? []}
        loading={entries.isLoading}
        error={entries.error ? saveError(entries.error) : undefined}
        onRetry={() => void entries.refetch()}
        emptyTitle="No classes"
        emptyDescription="The timetable is empty."
        onSelect={open}
        renderRow={(row) => (
          <Row
            title={row.class_name}
            subtitle={`${row.day_name ?? DAYS.find((d) => d.value === row.day_of_week)?.label} · ${
              row.start_time
            }–${row.end_time}${row.room ? ` · ${row.room}` : ''}`}
            trailing={row.instructor ?? undefined}
            status={row.is_active ? undefined : 'hidden'}
            statusTone={theme.warn}
          />
        )}
      />

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Muted>Members see this on the website and in the member app.</Muted>
    </Screen>
  )
}
