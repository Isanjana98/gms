import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
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
  Table,
  Td,
  Textarea,
  Th,
} from '../components/ui'
import { useToast } from '../components/Toast'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { P, useAuth } from '../lib/auth'
import { money } from '../lib/format'
import type { MembershipPlan } from '../lib/types'

/** SRS 5 - Membership packages. */
export function PlansPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [editing, setEditing] = useState<MembershipPlan | null>(null)
  const [creating, setCreating] = useState(false)

  const { data, isLoading, error } = useQuery({
    queryKey: ['plans-all'],
    queryFn: async () =>
      (await api.get<{ data: MembershipPlan[] }>('/membership-plans?include_inactive=true')).data.data,
  })

  const canManage = can(P.plansManage)

  return (
    <>
      <PageHeader
        title="Membership plans"
        subtitle="Packages members can buy"
        actions={canManage && <Button onClick={() => setCreating(true)}>Add plan</Button>}
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
            title="No plans yet"
            description="Create the packages your gym sells."
            action={canManage && <Button onClick={() => setCreating(true)}>Add plan</Button>}
          />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Plan</Th>
                <Th>Duration</Th>
                <Th align="right">Price</Th>
                <Th align="right">Joining fee</Th>
                <Th align="right">Active members</Th>
                <Th align="right" />
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {data.map((plan) => (
                <tr key={plan.id} className={plan.is_active ? '' : 'opacity-60'}>
                  <Td>
                    <span className="font-medium text-ink-900">{plan.name}</span>
                    <span className="block text-xs text-ink-500">{plan.code}</span>
                    {!plan.is_active && (
                      <span className="mt-1 inline-block">
                        <Badge>inactive</Badge>
                      </span>
                    )}
                  </Td>
                  <Td>{plan.duration_label}</Td>
                  <Td align="right">{money(plan.price)}</Td>
                  <Td align="right">
                    {plan.registration_fee > 0 ? money(plan.registration_fee) : <span className="text-ink-400">—</span>}
                  </Td>
                  <Td align="right">{plan.active_memberships_count ?? 0}</Td>
                  <Td align="right">
                    {canManage && (
                      <Button size="sm" variant="ghost" onClick={() => setEditing(plan)}>
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
        <PlanModal
          plan={editing}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
          onSaved={(message) => {
            toast.success(message ?? 'Plan saved.')
            setCreating(false)
            setEditing(null)
            queryClient.invalidateQueries({ queryKey: ['plans-all'] })
            queryClient.invalidateQueries({ queryKey: ['plans'] })
          }}
        />
      )}
    </>
  )
}

function PlanModal({
  plan,
  onClose,
  onSaved,
}: {
  plan: MembershipPlan | null
  onClose: () => void
  onSaved: (message?: string) => void
}) {
  const [form, setForm] = useState<Record<string, string | boolean>>({
    code: plan?.code ?? '',
    name: plan?.name ?? '',
    description: plan?.description ?? '',
    duration_value: String(plan?.duration_value ?? 1),
    duration_unit: plan?.duration_unit ?? 'MONTH',
    price: String(plan?.price ?? ''),
    default_discount: String(plan?.default_discount ?? 0),
    registration_fee: String(plan?.registration_fee ?? 0),
    grace_days: String(plan?.grace_days ?? 0),
    is_active: plan?.is_active ?? true,
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        duration_value: Number(form.duration_value),
        price: Number(form.price),
        default_discount: Number(form.default_discount),
        registration_fee: Number(form.registration_fee),
        grace_days: Number(form.grace_days),
      }

      return plan
        ? (await api.put(`/membership-plans/${plan.id}`, payload)).data
        : (await api.post('/membership-plans', payload)).data
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
      title={plan ? `Edit ${plan.name}` : 'Add membership plan'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button form="plan-form" type="submit" loading={save.isPending}>
            {plan ? 'Save changes' : 'Create plan'}
          </Button>
        </>
      }
    >
      <form id="plan-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name" required error={errors.name}>
            <Input value={String(form.name)} onChange={set('name')} placeholder="Monthly" required />
          </Field>

          <Field label="Code" required error={errors.code}>
            <Input value={String(form.code)} onChange={set('code')} placeholder="MONTHLY" required />
          </Field>

          <Field label="Duration" required error={errors.duration_value}>
            <Input type="number" min="1" value={String(form.duration_value)} onChange={set('duration_value')} required />
          </Field>

          <Field label="Unit" required>
            <Select value={String(form.duration_unit)} onChange={set('duration_unit')}>
              <option value="DAY">Days</option>
              <option value="WEEK">Weeks</option>
              <option value="MONTH">Months</option>
              <option value="YEAR">Years</option>
            </Select>
          </Field>

          <Field label="Price" required error={errors.price}>
            <Input type="number" step="0.01" min="0" value={String(form.price)} onChange={set('price')} required />
          </Field>

          <Field label="Joining fee" error={errors.registration_fee} hint="Charged on the first membership only.">
            <Input
              type="number"
              step="0.01"
              min="0"
              value={String(form.registration_fee)}
              onChange={set('registration_fee')}
            />
          </Field>

          <Field label="Default discount" error={errors.default_discount}>
            <Input
              type="number"
              step="0.01"
              min="0"
              value={String(form.default_discount)}
              onChange={set('default_discount')}
            />
          </Field>

          <Field label="Grace days" error={errors.grace_days} hint="Extra days of access after expiry.">
            <Input type="number" min="0" value={String(form.grace_days)} onChange={set('grace_days')} />
          </Field>
        </div>

        <Field label="Description">
          <Textarea value={String(form.description)} onChange={set('description')} />
        </Field>

        <label className="flex items-center gap-2 text-sm text-ink-700">
          <input
            type="checkbox"
            checked={Boolean(form.is_active)}
            onChange={(e) => setForm((prev) => ({ ...prev, is_active: e.target.checked }))}
            className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
          />
          Available for sale
        </label>

        {plan && (
          <p className="text-xs text-ink-500">
            Changing the price here does not alter memberships already sold — those keep the price they were sold at.
          </p>
        )}
      </form>
    </Modal>
  )
}
