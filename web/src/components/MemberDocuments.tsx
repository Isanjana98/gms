import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState, type FormEvent } from 'react'
import {
  Alert,
  Badge,
  Button,
  EmptyState,
  Field,
  FileButton,
  Input,
  LoadingBlock,
  Modal,
  Select,
  Table,
  Td,
  Textarea,
  Th,
} from './ui'
import { useConfirm } from './Confirm'
import { useToast } from './Toast'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { date } from '../lib/format'

export interface MemberDocumentRow {
  id: number
  member_id: number
  replaces_id: number | null
  type: string
  title: string
  original_filename: string
  mime_type: string
  size_label: string
  signed_on: string | null
  expires_on: string | null
  is_expired: boolean
  notes: string | null
  uploaded_by: string | null
  created_at: string | null
}

const TYPE_LABELS: Record<string, string> = {
  AGREEMENT: 'Membership agreement',
  ID_PROOF: 'Identity document',
  MEDICAL: 'Medical clearance',
  WAIVER: 'Liability waiver',
  PHOTO_CONSENT: 'Photography consent',
  OTHER: 'Other',
}

const MAX_BYTES = 10 * 1024 * 1024

/**
 * Member documents: agreements, ID copies, medical clearances.
 *
 * Files are fetched through the authenticated API and never linked directly —
 * an agreement carries a signature and usually an ID number, and must not be
 * reachable from a public URL.
 */
export function MemberDocuments({
  memberId,
  canManage,
}: {
  memberId: number
  canManage: boolean
}) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [uploading, setUploading] = useState<{ replaces?: MemberDocumentRow } | null>(null)
  const [editing, setEditing] = useState<MemberDocumentRow | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['member-documents', memberId],
    queryFn: async () =>
      (await api.get<{ data: MemberDocumentRow[]; types: string[] }>(`/members/${memberId}/documents`)).data,
  })

  const remove = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/members/${memberId}/documents/${id}`)).data,
    onSuccess: (result: { message: string }) => {
      toast.success(result.message)
      queryClient.invalidateQueries({ queryKey: ['member-documents', memberId] })
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  if (isLoading) return <LoadingBlock />
  if (error) {
    return (
      <div className="p-5">
        <Alert>{errorMessage(error)}</Alert>
      </div>
    )
  }

  const documents = data?.data ?? []

  return (
    <div className="p-5">
      {documents.length === 0 ? (
        <EmptyState
          title="No documents on file"
          description="Upload the signed membership agreement, an ID copy or a medical clearance."
          action={canManage && <Button onClick={() => setUploading({})}>Upload document</Button>}
        />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Document</Th>
                <Th>Type</Th>
                <Th>Signed</Th>
                <Th>Expires</Th>
                <Th>Uploaded</Th>
                <Th align="right" />
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {documents.map((doc) => (
                <tr key={doc.id}>
                  <Td>
                    <span className="font-medium text-ink-900">{doc.title}</span>
                    <span className="block text-xs text-ink-500">
                      {doc.original_filename} · {doc.size_label}
                      {doc.replaces_id ? ' · replaces an earlier version' : ''}
                    </span>
                    {doc.notes && <span className="block text-xs italic text-ink-400">{doc.notes}</span>}
                  </Td>
                  <Td>
                    <span className="text-xs">{TYPE_LABELS[doc.type] ?? doc.type}</span>
                  </Td>
                  <Td>{doc.signed_on ? date(doc.signed_on) : <span className="text-ink-400">—</span>}</Td>
                  <Td>
                    {doc.expires_on ? (
                      <>
                        <span className="block text-sm">{date(doc.expires_on)}</span>
                        {doc.is_expired && <Badge tone="danger">expired</Badge>}
                      </>
                    ) : (
                      <span className="text-ink-400">—</span>
                    )}
                  </Td>
                  <Td>
                    <span className="block text-xs">{doc.created_at ? date(doc.created_at) : '—'}</span>
                    <span className="block text-xs text-ink-500">{doc.uploaded_by ?? ''}</span>
                  </Td>
                  <Td align="right">
                    <div className="flex flex-wrap justify-end gap-1.5">
                      <FileButton
                        url={`/members/${memberId}/documents/${doc.id}`}
                        filename={doc.original_filename}
                        mode={doc.mime_type === 'application/pdf' || doc.mime_type.startsWith('image/') ? 'view' : 'download'}
                        onError={(text) => toast.error(text)}
                      >
                        View
                      </FileButton>
                      {canManage && (
                        <>
                          <Button size="sm" variant="ghost" onClick={() => setEditing(doc)}>
                            Details
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setUploading({ replaces: doc })}>
                            Replace
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={async () => {
                              const ok = await confirm({
                                title: `Remove "${doc.title}"?`,
                                tone: 'danger',
                                confirmLabel: 'Remove document',
                                message: (
                                  <>
                                    It disappears from this member's file. The uploaded file itself is kept, so an
                                    administrator can restore it.
                                  </>
                                ),
                                detail: `${doc.original_filename} · ${doc.size_label}`,
                              })

                              if (ok) remove.mutate(doc.id)
                            }}
                          >
                            Remove
                          </Button>
                        </>
                      )}
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>

          {canManage && (
            <div className="mt-4">
              <Button variant="secondary" size="sm" onClick={() => setUploading({})}>
                Upload document
              </Button>
            </div>
          )}
        </>
      )}

      {uploading && (
        <UploadModal
          memberId={memberId}
          replaces={uploading.replaces}
          onClose={() => setUploading(null)}
          onDone={(message) => {
            setUploading(null)
            toast.success(message)
            queryClient.invalidateQueries({ queryKey: ['member-documents', memberId] })
          }}
        />
      )}

      {editing && (
        <DetailsModal
          memberId={memberId}
          document={editing}
          onClose={() => setEditing(null)}
          onDone={(message) => {
            setEditing(null)
            toast.success(message)
            queryClient.invalidateQueries({ queryKey: ['member-documents', memberId] })
          }}
        />
      )}
    </div>
  )
}

function UploadModal({
  memberId,
  replaces,
  onClose,
  onDone,
}: {
  memberId: number
  replaces?: MemberDocumentRow
  onClose: () => void
  onDone: (message: string) => void
}) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [form, setForm] = useState({
    type: replaces?.type ?? 'AGREEMENT',
    title: replaces?.title ?? '',
    signed_on: '',
    expires_on: '',
    notes: '',
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const upload = useMutation({
    mutationFn: async () => {
      const body = new FormData()
      body.append('file', file!)
      body.append('type', form.type)
      if (form.title) body.append('title', form.title)
      if (form.signed_on) body.append('signed_on', form.signed_on)
      if (form.expires_on) body.append('expires_on', form.expires_on)
      if (form.notes) body.append('notes', form.notes)
      if (replaces) body.append('replaces_id', String(replaces.id))

      return (await api.post(`/members/${memberId}/documents`, body)).data
    },
    onSuccess: (result: { message: string }) => onDone(result.message),
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  const chooseFile = (chosen: File | undefined) => {
    if (!chosen) return

    if (chosen.size > MAX_BYTES) {
      setError(`That file is ${(chosen.size / 1024 / 1024).toFixed(1)} MB. The limit is 10 MB.`)
      return
    }

    setError(null)
    setFile(chosen)
  }

  const submit = (event: FormEvent) => {
    event.preventDefault()

    if (!file) {
      setError('Choose a file to upload.')
      return
    }

    upload.mutate()
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={replaces ? `Replace "${replaces.title}"` : 'Upload document'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button form="document-upload" type="submit" loading={upload.isPending} disabled={!file}>
            Upload
          </Button>
        </>
      }
    >
      <form id="document-upload" onSubmit={submit} className="space-y-4">
        {error && <Alert>{error}</Alert>}

        {replaces && (
          <Alert tone="info">
            The current version is kept and stays available to administrators. Nothing is destroyed.
          </Alert>
        )}

        <Field label="File" required hint="PDF, image or Word document, up to 10 MB.">
          <input
            ref={inputRef}
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
            onChange={(e) => chooseFile(e.target.files?.[0])}
            className="block w-full text-sm text-ink-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
          />
        </Field>

        {file && (
          <p className="text-xs text-ink-500">
            {file.name} · {(file.size / 1024).toFixed(0)} KB
          </p>
        )}

        <Field label="Type" required error={errors.type}>
          <Select value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}>
            {Object.entries(TYPE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Title" hint="Left blank, a sensible name is chosen from the type." error={errors.title}>
          <Input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Signed on" error={errors.signed_on}>
            <Input
              type="date"
              value={form.signed_on}
              onChange={(e) => setForm((f) => ({ ...f, signed_on: e.target.value }))}
            />
          </Field>

          <Field label="Expires on" hint="For agreements that need re-signing." error={errors.expires_on}>
            <Input
              type="date"
              value={form.expires_on}
              onChange={(e) => setForm((f) => ({ ...f, expires_on: e.target.value }))}
            />
          </Field>
        </div>

        <Field label="Notes">
          <Textarea value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} rows={2} />
        </Field>
      </form>
    </Modal>
  )
}

function DetailsModal({
  memberId,
  document,
  onClose,
  onDone,
}: {
  memberId: number
  document: MemberDocumentRow
  onClose: () => void
  onDone: (message: string) => void
}) {
  const [form, setForm] = useState({
    type: document.type,
    title: document.title,
    signed_on: document.signed_on ?? '',
    expires_on: document.expires_on ?? '',
    notes: document.notes ?? '',
  })
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: async () =>
      (
        await api.put(`/members/${memberId}/documents/${document.id}`, {
          ...form,
          signed_on: form.signed_on || null,
          expires_on: form.expires_on || null,
          notes: form.notes || null,
        })
      ).data,
    onSuccess: (result: { message: string }) => onDone(result.message),
    onError: (err) => setError(errorMessage(err)),
  })

  return (
    <Modal
      open
      onClose={onClose}
      title="Document details"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert>{error}</Alert>}

        <p className="rounded-lg bg-ink-50 p-3 text-xs text-ink-600">
          {document.original_filename} · {document.size_label}. To change the file itself, use <strong>Replace</strong>.
        </p>

        <Field label="Type">
          <Select value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}>
            {Object.entries(TYPE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Title">
          <Input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Signed on">
            <Input
              type="date"
              value={form.signed_on}
              onChange={(e) => setForm((f) => ({ ...f, signed_on: e.target.value }))}
            />
          </Field>
          <Field label="Expires on">
            <Input
              type="date"
              value={form.expires_on}
              onChange={(e) => setForm((f) => ({ ...f, expires_on: e.target.value }))}
            />
          </Field>
        </div>

        <Field label="Notes">
          <Textarea value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} rows={2} />
        </Field>
      </div>
    </Modal>
  )
}
