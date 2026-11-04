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
  Th,
} from '../components/ui'
import { useToast } from '../components/Toast'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { relative } from '../lib/format'
import type { Branch, Paginated } from '../lib/types'

interface StaffUser {
  id: number
  name: string
  email: string
  phone: string | null
  branch_id: number | null
  branch: { id: number; name: string } | null
  is_active: boolean
  roles: string[]
  last_login_at: string | null
}

interface RoleInfo {
  id: number
  name: string
  permissions: string[]
  users_count: number
}

/** SRS 3 - Staff accounts and roles. */
export function UsersPage() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [editing, setEditing] = useState<StaffUser | null>(null)
  const [creating, setCreating] = useState(false)

  const users = useQuery({
    queryKey: ['users'],
    queryFn: async () => (await api.get<Paginated<StaffUser>>('/users?per_page=100')).data,
  })

  const roles = useQuery({
    queryKey: ['roles'],
    queryFn: async () => (await api.get<{ roles: RoleInfo[] }>('/roles')).data.roles,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['users'] })

  return (
    <>
      <PageHeader
        title="Staff & roles"
        subtitle="Who can sign in, and what they can do"
        actions={<Button onClick={() => setCreating(true)}>Add staff account</Button>}
      />

      <Card padded={false}>
        {users.error ? (
          <div className="p-5">
            <Alert>{errorMessage(users.error)}</Alert>
          </div>
        ) : users.isLoading ? (
          <LoadingBlock />
        ) : !users.data?.data?.length ? (
          <EmptyState title="No staff accounts" />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Name</Th>
                <Th>Email</Th>
                <Th>Role</Th>
                <Th>Branch</Th>
                <Th>Last signed in</Th>
                <Th align="right" />
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {users.data.data.map((user) => (
                <tr key={user.id} className={user.is_active ? '' : 'opacity-60'}>
                  <Td>
                    <span className="font-medium text-ink-900">{user.name}</span>
                    {!user.is_active && (
                      <span className="ml-2">
                        <Badge tone="danger">deactivated</Badge>
                      </span>
                    )}
                  </Td>
                  <Td>{user.email}</Td>
                  <Td>{user.roles.join(', ') || <span className="text-ink-400">none</span>}</Td>
                  <Td>{user.branch?.name ?? <span className="text-ink-400">all branches</span>}</Td>
                  <Td>{user.last_login_at ? relative(user.last_login_at) : 'never'}</Td>
                  <Td align="right">
                    <Button size="sm" variant="ghost" onClick={() => setEditing(user)}>
                      Edit
                    </Button>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {roles.data && (
        <Card title="Roles" subtitle="What each role can reach" className="mt-6" padded={false}>
          <Table>
            <thead>
              <tr>
                <Th>Role</Th>
                <Th align="right">Staff</Th>
                <Th align="right">Permissions</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {roles.data.map((role) => (
                <tr key={role.id}>
                  <Td>
                    <span className="font-medium">{role.name}</span>
                  </Td>
                  <Td align="right">{role.users_count}</Td>
                  <Td align="right">
                    {role.permissions.length === 0 ? (
                      <span className="text-xs text-ink-500">full access</span>
                    ) : (
                      role.permissions.length
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {(creating || editing) && (
        <UserModal
          user={editing}
          roles={roles.data ?? []}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
          onSaved={(message) => {
            toast.success(message ?? 'Staff account saved.')
            setCreating(false)
            setEditing(null)
            refresh()
          }}
        />
      )}
    </>
  )
}

function UserModal({
  user,
  roles,
  onClose,
  onSaved,
}: {
  user: StaffUser | null
  roles: RoleInfo[]
  onClose: () => void
  onSaved: (message?: string) => void
}) {
  const [form, setForm] = useState<Record<string, string | boolean>>({
    name: user?.name ?? '',
    email: user?.email ?? '',
    phone: user?.phone ?? '',
    password: '',
    role: user?.roles?.[0] ?? 'Receptionist',
    branch_id: user?.branch_id ? String(user.branch_id) : '',
    is_active: user?.is_active ?? true,
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const { data: branches } = useQuery({
    queryKey: ['branches'],
    queryFn: async () => (await api.get<{ data: Branch[] }>('/branches')).data.data,
  })

  const save = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        ...form,
        branch_id: form.branch_id ? Number(form.branch_id) : null,
      }

      // Blank means "leave the password alone" when editing.
      if (!payload.password) delete payload.password
      if (!payload.phone) delete payload.phone

      return user ? (await api.put(`/users/${user.id}`, payload)).data : (await api.post('/users', payload)).data
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
      title={user ? `Edit ${user.name}` : 'Add staff account'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button form="user-form" type="submit" loading={save.isPending}>
            Save
          </Button>
        </>
      }
    >
      <form id="user-form" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <Field label="Name" required error={errors.name}>
          <Input value={String(form.name)} onChange={set('name')} required autoFocus />
        </Field>

        <Field label="Email" required error={errors.email}>
          <Input type="email" value={String(form.email)} onChange={set('email')} required autoComplete="off" />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Telephone">
            <Input value={String(form.phone)} onChange={set('phone')} />
          </Field>

          <Field
            label="Password"
            required={!user}
            error={errors.password}
            hint={user ? 'Leave blank to keep the current password.' : 'At least 8 characters.'}
          >
            <Input
              type="password"
              value={String(form.password)}
              onChange={set('password')}
              autoComplete="new-password"
              required={!user}
            />
          </Field>

          <Field label="Role" required error={errors.role}>
            <Select value={String(form.role)} onChange={set('role')}>
              {roles.map((role) => (
                <option key={role.id} value={role.name}>
                  {role.name}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Branch" hint="Leave empty to allow access to every branch.">
            <Select value={String(form.branch_id)} onChange={set('branch_id')}>
              <option value="">All branches</option>
              {branches?.map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branch.name}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <label className="flex items-center gap-2 text-sm text-ink-700">
          <input
            type="checkbox"
            checked={Boolean(form.is_active)}
            onChange={(e) => setForm((prev) => ({ ...prev, is_active: e.target.checked }))}
            className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
          />
          Account is active
        </label>

        {user && form.is_active === false && (
          <Alert tone="warning">
            Deactivating signs this account out everywhere immediately.
          </Alert>
        )}
      </form>
    </Modal>
  )
}
