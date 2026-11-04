import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useConfirm } from './Confirm'
import { useToast } from './Toast'
import { Alert, Avatar, Button } from './ui'
import { api, errorMessage } from '../lib/api'

const MAX_BYTES = 4 * 1024 * 1024
const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp']

/**
 * Member profile photo (SRS 4).
 *
 * Uploads on its own endpoint rather than as a field in the member form: a
 * file forces the whole request into multipart, where every other value
 * arrives as a string and a cleared date becomes the literal "null".
 *
 * Size and type are checked here as well as on the server — not as security,
 * but so someone who picks a 12 MB photo is told immediately instead of
 * waiting for an upload that was always going to be refused.
 */
export function PhotoUpload({
  memberId,
  photoUrl,
  name,
  canEdit,
}: {
  memberId: number
  photoUrl?: string | null
  name?: string | null
  canEdit: boolean
}) {
  const queryClient = useQueryClient()
  const confirm = useConfirm()
  const toast = useToast()
  const inputRef = useRef<HTMLInputElement>(null)
  const [error, setError] = useState<string | null>(null)
  const [preview, setPreview] = useState<string | null>(null)

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['member', String(memberId)] })
    queryClient.invalidateQueries({ queryKey: ['members'] })
  }

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData()
      body.append('photo', file)

      // Let the browser set Content-Type, so it can add the multipart
      // boundary. Setting it by hand produces a request the server cannot
      // parse, and the failure is opaque.
      return (await api.post(`/members/${memberId}/photo`, body)).data
    },
    onSuccess: () => {
      setError(null)
      setPreview(null)
      toast.success('Photo updated.')
      refresh()
    },
    onError: (err) => {
      setPreview(null)
      setError(errorMessage(err))
    },
  })

  const remove = useMutation({
    mutationFn: async () => (await api.delete(`/members/${memberId}/photo`)).data,
    onSuccess: () => {
      setError(null)
      toast.success('Photo removed.')
      refresh()
    },
    onError: (err) => setError(errorMessage(err)),
  })

  const handleFile = (file: File | undefined) => {
    if (!file) return

    if (!ACCEPTED.includes(file.type)) {
      setError('Choose a JPG, PNG or WebP image.')
      return
    }

    if (file.size > MAX_BYTES) {
      setError(`That image is ${(file.size / 1024 / 1024).toFixed(1)} MB. The limit is 4 MB.`)
      return
    }

    setError(null)
    // Show the chosen image straight away; the upload follows.
    setPreview(URL.createObjectURL(file))
    upload.mutate(file)
  }

  return (
    <div className="flex flex-col items-center text-center">
      <Avatar name={name} url={preview ?? photoUrl} size="lg" />

      {canEdit && (
        <>
          <input
            ref={inputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={(e) => {
              handleFile(e.target.files?.[0])
              // Reset so choosing the same file twice still fires a change.
              e.target.value = ''
            }}
          />

          <div className="mt-2 flex gap-1.5">
            <Button size="sm" variant="ghost" loading={upload.isPending} onClick={() => inputRef.current?.click()}>
              {photoUrl ? 'Change photo' : 'Add photo'}
            </Button>
            {photoUrl && (
              <Button
                size="sm"
                variant="ghost"
                loading={remove.isPending}
                onClick={async () => {
                  const ok = await confirm({
                    title: 'Remove this photo?',
                    tone: 'danger',
                    confirmLabel: 'Remove photo',
                    message: 'The image file is deleted. A new one can be uploaded at any time.',
                  })

                  if (ok) remove.mutate()
                }}
              >
                Remove
              </Button>
            )}
          </div>
        </>
      )}

      {error && (
        <div className="mt-2 w-full">
          <Alert>{error}</Alert>
        </div>
      )}
    </div>
  )
}
