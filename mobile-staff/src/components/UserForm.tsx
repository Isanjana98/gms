import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../lib/api'
import { fieldErrors, saveError, useSaveResource } from '../lib/resource'
import type { Branch, Role, StaffAccount } from '../lib/types'
import { FormRow, FormSection, Outcome, SelectField, SwitchField, TextField } from './form'
import { Button, Muted } from './ui'

/**
 * Create or change a staff account.
 *
 * The role list comes from the server rather than being hard-coded, because a
 * role added in the admin panel must be assignable here without shipping a new
 * build — and a stale copy of the list is how somebody ends up unable to make
 * the one account they need.
 */
export function UserForm({
  initial,
  onSaved,
}: {
  initial?: StaffAccount
  onSaved: (message: string) => void
}) {
  const [name, setName] = useState(initial?.name ?? '')
  const [email, setEmail] = useState(initial?.email ?? '')
  const [phone, setPhone] = useState(initial?.phone ?? '')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState(initial?.roles?.[0] ?? '')
  const [branchId, setBranchId] = useState<string | number | null>(initial?.branch_id ?? null)
  const [active, setActive] = useState(initial?.is_active ?? true)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const roles = useQuery({
    queryKey: ['/roles'],
    queryFn: async () => (await api.get<{ roles: Role[] }>('/roles')).data.roles,
  })

  const branches = useQuery({
    queryKey: ['/branches'],
    queryFn: async () => (await api.get<{ data: Branch[] } | Branch[]>('/branches')).data,
  })

  const branchRows = Array.isArray(branches.data) ? branches.data : (branches.data?.data ?? [])

  const save = useSaveResource('/users', { id: initial?.id, method: initial ? 'patch' : 'post' })
  const errors = fieldErrors(save.error)

  const submit = () => {
    setResult(null)

    save.mutate(
      {
        name: name.trim(),
        email: email.trim(),
        phone: phone.trim() || undefined,
        // Blank on an edit means "leave it alone", which is why it is omitted
        // rather than sent empty.
        password: password ? password : undefined,
        role,
        branch_id: branchId ?? undefined,
        is_active: active,
      },
      {
        onSuccess: () => {
          setPassword('')
          onSaved(initial ? 'Account updated.' : 'Account created.')
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  return (
    <>
      <FormSection title="Who">
        <FormRow label="Name" error={errors.name}>
          <TextField testID="name" value={name} onChange={setName} autoCapitalize="words" />
        </FormRow>
        <FormRow label="Email" hint="What they sign in with." error={errors.email}>
          <TextField
            testID="email"
            value={email}
            onChange={setEmail}
            keyboardType="email-address"
            autoCapitalize="none"
          />
        </FormRow>
        <FormRow label="Telephone" error={errors.phone}>
          <TextField value={phone} onChange={setPhone} keyboardType="phone-pad" autoCapitalize="none" />
        </FormRow>
      </FormSection>

      <FormSection title="What they can do">
        <FormRow label="Role" error={errors.role}>
          <SelectField
            testID="role"
            value={role}
            options={(roles.data ?? []).map((r) => ({
              label: r.name,
              value: r.name,
              hint: r.permissions.length ? `${r.permissions.length} permissions` : 'Everything',
            }))}
            onChange={(v) => setRole(String(v))}
            placeholder={roles.isLoading ? 'Loading…' : 'Choose a role'}
          />
        </FormRow>

        <FormRow
          label="Branch"
          hint="Leave unset for someone who works across all of them."
          error={errors.branch_id}
        >
          <SelectField
            value={branchId}
            options={branchRows.map((b) => ({ label: b.name, value: b.id, hint: b.code }))}
            onChange={setBranchId}
            placeholder="All branches"
          />
        </FormRow>

        <SwitchField
          label="Can sign in"
          hint="Turning this off blocks them immediately and ends their sessions."
          value={active}
          onChange={setActive}
        />
      </FormSection>

      <FormSection title="Password">
        <FormRow
          label={initial ? 'New password' : 'Password'}
          hint={initial ? 'Leave blank to keep the current one.' : 'At least eight characters.'}
          error={errors.password}
        >
          <TextField value={password} onChange={setPassword} secure autoCapitalize="none" />
        </FormRow>
        <Muted>Tell them in person, not by email.</Muted>
      </FormSection>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Button label={initial ? 'Save changes' : 'Create account'} onPress={submit} loading={save.isPending} />
    </>
  )
}
