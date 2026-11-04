import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import {
  Alert,
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
} from '../components/ui'
import { useToast } from '../components/Toast'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { date } from '../lib/format'
import type { Trainer } from '../lib/types'

/** SRS 19 - Trainers. */
export function TrainersPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [editing, setEditing] = useState<Trainer | null>(null)
  const [creating, setCreating] = useState(false)

  const { data, isLoading, error } = useQuery({
    queryKey: ['trainers'],
    queryFn: async () => (await api.get<{ data: Trainer[] }>('/trainers?include_inactive=true')).data.data,
  })

  const canManage = can(P.trainersManage)

  return (
    <>
      <PageHeader
        title="Trainers"
        subtitle="Staff who coach members"
        actions={canManage && <Button onClick={() => setCreating(true)}>Add trainer</Button>}
      />

      <Card padded={false}>
        {error ? (
          <div className="p-5">
            <Alert>{errorMessage(error)}</Alert>
          </div>
        ) : isLoading ? (
          <LoadingBlock />
        ) : !data?.length ? (
          <EmptyState
            title="No trainers yet"
            action={canManage && <Button onClick={() => setCreating(true)}>Add trainer</Button>}
          />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Trainer</Th>
                <Th>Specialization</Th>
                <Th>Contact</Th>
                <Th>Joined</Th>
                <Th align="right">Members</Th>
                <Th align="right">Status</Th>
                <Th align="right" />
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {data.map((trainer) => (
                <tr key={trainer.id}>
                  <Td>
                    <span className="font-medium text-ink-900">{trainer.full_name}</span>
                    <span className="block text-xs text-ink-500">{trainer.trainer_code}</span>
                  </Td>
                  <Td>{trainer.specialization ?? <span className="text-ink-400">—</span>}</Td>
                  <Td>
                    <span className="block text-sm">{trainer.telephone ?? '—'}</span>
                    <span className="block text-xs text-ink-500">{trainer.email ?? ''}</span>
                  </Td>
                  <Td>{date(trainer.joining_date)}</Td>
                  <Td align="right">{trainer.members_count ?? 0}</Td>
                  <Td align="right">
                    <StatusBadge status={trainer.status} />
                  </Td>
                  <Td align="right">
                    {canManage && (
                      <Button size="sm" variant="ghost" onClick={() => setEditing(trainer)}>
                        Edit
                      </Button>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {(creating || editing) && (
        <TrainerModal
          trainer={editing}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
          onSaved={(message) => {
            toast.success(message ?? 'Trainer saved.')
            setCreating(false)
            setEditing(null)
            queryClient.invalidateQueries({ queryKey: ['trainers'] })
          }}
        />
      )}
    </>
  )
}

function TrainerModal({
  trainer,
  onClose,
  onSaved,
}: {
  trainer: Trainer | null
  onClose: () => void
  onSaved: (message?: string) => void
}) {
  const [form, setForm] = useState<Record<string, string>>({
    full_name: trainer?.full_name ?? '',
    telephone: trainer?.telephone ?? '',
    email: trainer?.email ?? '',
    specialization: trainer?.specialization ?? '',
    joining_date: trainer?.joining_date ?? '',
    status: trainer?.status ?? 'ACTIVE',
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: async () => {
      const payload = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''))

      return trainer
        ? (await api.put(`/trainers/${trainer.id}`, payload)).data
        : (await api.post('/trainers', payload)).data
    },
    onSuccess: (result: { message?: string }) => onSaved(result?.message),
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
      title={trainer ? `Edit ${trainer.full_name}` : 'Add trainer'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button form="trainer-form" type="submit" loading={save.isPending}>
            Save
          </Button>
        </>
      }
    >
      <form id="trainer-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <Field label="Full name" required error={errors.full_name}>
          <Input value={form.full_name} onChange={set('full_name')} required autoFocus />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Telephone" error={errors.telephone}>
            <Input value={form.telephone} onChange={set('telephone')} />
          </Field>
          <Field label="Email" error={errors.email}>
            <Input type="email" value={form.email} onChange={set('email')} />
          </Field>
          <Field label="Specialization" error={errors.specialization}>
            <Input value={form.specialization} onChange={set('specialization')} placeholder="Strength & conditioning" />
          </Field>
          <Field label="Joining date">
            <Input type="date" value={form.joining_date} onChange={set('joining_date')} />
          </Field>
        </div>

        <Field label="Status">
          <Select value={form.status} onChange={set('status')}>
            <option value="ACTIVE">Active</option>
            <option value="INACTIVE">Inactive</option>
          </Select>
        </Field>
      </form>
    </Modal>
  )
}
