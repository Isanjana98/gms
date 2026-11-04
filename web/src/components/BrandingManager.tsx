import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { Badge, Button, Card, EmptyState, Field, Input, LoadingBlock } from './ui'
import { useConfirm } from './Confirm'
import { useToast } from './Toast'
import { api, errorMessage } from '../lib/api'
import { useBranding } from '../lib/branding'

interface BrandAssetRow {
  id: number
  type: 'LOGO' | 'LOGIN_BACKGROUND'
  url: string
  original_filename: string
  size_label: string
  caption: string | null
  sort_order: number
  is_active: boolean
  uploaded_by: string | null
}

const MAX_BYTES = 5 * 1024 * 1024
const ACCEPTED = 'image/jpeg,image/png,image/webp,image/svg+xml'

/**
 * The gym's own name, logo and sign-in imagery.
 *
 * Every change here is visible to anyone who reaches the sign-in screen, so
 * the screen shows what it will look like rather than describing it — a live
 * preview of the panel, not a list of filenames.
 */
export function BrandingManager() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const branding = useBranding()
  const [name, setName] = useState<string | null>(null)
  const [tagline, setTagline] = useState<string | null>(null)

  const assets = useQuery({
    queryKey: ['brand-assets'],
    queryFn: async () => (await api.get<{ data: BrandAssetRow[] }>('/branding/assets')).data.data,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['brand-assets'] })
    queryClient.invalidateQueries({ queryKey: ['branding'] })
  }

  const saveText = useMutation({
    mutationFn: async () =>
      (
        await api.put('/branding', {
          gym_name: name ?? branding.gym_name,
          tagline: tagline ?? branding.tagline ?? '',
        })
      ).data,
    onSuccess: () => {
      toast.success('Branding updated.')
      setName(null)
      setTagline(null)
      refresh()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const remove = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/branding/assets/${id}`)).data,
    onSuccess: () => {
      toast.success('Image removed.')
      refresh()
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const toggle = useMutation({
    mutationFn: async ({ id, is_active }: { id: number; is_active: boolean }) =>
      (await api.put(`/branding/assets/${id}`, { is_active })).data,
    onSuccess: refresh,
    onError: (err) => toast.error(errorMessage(err)),
  })

  const logo = assets.data?.find((a) => a.type === 'LOGO')
  const backgrounds = assets.data?.filter((a) => a.type === 'LOGIN_BACKGROUND') ?? []

  const dirty =
    (name !== null && name !== branding.gym_name) || (tagline !== null && tagline !== (branding.tagline ?? ''))

  return (
    <div className="space-y-6">
      <Card
        title="Gym identity"
        subtitle="Shown on the sign-in screen, in the sidebar and on printed receipts"
        actions={
          <Button size="sm" disabled={!dirty} loading={saveText.isPending} onClick={() => saveText.mutate()}>
            {dirty ? 'Save' : 'Saved'}
          </Button>
        }
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Gym name" required>
            <Input
              value={name ?? branding.gym_name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Iron House Fitness"
            />
          </Field>

          <Field label="Tagline" hint="A short line under the name on the sign-in screen.">
            <Input
              value={tagline ?? branding.tagline ?? ''}
              onChange={(e) => setTagline(e.target.value)}
              placeholder="Every member, every visit, in one place."
            />
          </Field>
        </div>
      </Card>

      <Card title="Logo" subtitle="Replaces the lettermark. A transparent PNG or SVG works best.">
        {assets.isLoading ? (
          <LoadingBlock />
        ) : (
          <div className="flex flex-wrap items-center gap-5">
            <div className="flex h-20 w-20 items-center justify-center rounded-xl border border-ink-200 bg-ink-50 p-2">
              {logo ? (
                <img src={logo.url} alt="" className="max-h-full max-w-full object-contain" />
              ) : (
                <span className="flex h-12 w-12 items-center justify-center rounded-lg bg-brand-600 text-xl font-bold text-white">
                  {branding.gym_name.charAt(0).toUpperCase()}
                </span>
              )}
            </div>

            <div className="min-w-0 flex-1">
              <p className="text-sm text-ink-600">
                {logo ? (
                  <>
                    {logo.original_filename} · {logo.size_label}
                  </>
                ) : (
                  'No logo uploaded — the first letter of the gym name is used.'
                )}
              </p>

              <div className="mt-2 flex flex-wrap gap-2">
                <UploadButton
                  type="LOGO"
                  label={logo ? 'Replace logo' : 'Upload logo'}
                  onDone={(message) => {
                    toast.success(message)
                    refresh()
                  }}
                  onError={(text) => toast.error(text)}
                />
                {logo && (
                  <Button size="sm" variant="ghost" loading={remove.isPending} onClick={() => remove.mutate(logo.id)}>
                    Remove
                  </Button>
                )}
              </div>
            </div>
          </div>
        )}
      </Card>

      <Card
        title="Sign-in images"
        subtitle={
          backgrounds.length > 1
            ? 'Shown behind the sign-in panel, fading between them every few seconds'
            : 'Shown behind the sign-in panel'
        }
        actions={
          <UploadButton
            type="LOGIN_BACKGROUND"
            label="Add image"
            onDone={(message) => {
              toast.success(message)
              refresh()
            }}
            onError={(text) => toast.error(text)}
          />
        }
      >
        {assets.isLoading ? (
          <LoadingBlock />
        ) : backgrounds.length === 0 ? (
          <EmptyState
            title="No sign-in images"
            description="Without any, the panel is drawn with the brand gradient. Upload a wide, uncluttered photo of your gym — the gradient is laid over it so the text stays readable."
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {backgrounds.map((asset) => (
              <figure
                key={asset.id}
                className={`overflow-hidden rounded-lg border border-ink-200 ${asset.is_active ? '' : 'opacity-60'}`}
              >
                <div className="relative aspect-[16/10] bg-ink-100">
                  <img src={asset.url} alt="" className="h-full w-full object-cover" />
                  {/* The same two layers the sign-in panel applies, so the
                      preview is what a visitor actually sees rather than an
                      approximation of it. */}
                  <div
                    className="absolute inset-0"
                    style={{
                      background:
                        'linear-gradient(135deg, rgba(12,20,38,0.55) 0%, rgba(26,79,180,0.34) 60%, rgba(49,130,246,0.22) 100%)',
                    }}
                  />
                  <div
                    className="absolute inset-x-0 bottom-0 h-2/3"
                    style={{
                      background:
                        'linear-gradient(to top, rgba(8,13,26,0.88) 0%, rgba(8,13,26,0.35) 55%, transparent 100%)',
                    }}
                  />
                  <figcaption className="absolute inset-x-0 bottom-0 p-3 text-xs font-medium text-white">
                    {branding.tagline || branding.gym_name}
                  </figcaption>
                  {!asset.is_active && (
                    <span className="absolute right-2 top-2">
                      <Badge>hidden</Badge>
                    </span>
                  )}
                </div>

                <div className="flex items-center justify-between gap-2 px-3 py-2">
                  <span className="min-w-0 truncate text-xs text-ink-500" title={asset.original_filename}>
                    {asset.original_filename} · {asset.size_label}
                  </span>
                  <div className="flex shrink-0 gap-1">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => toggle.mutate({ id: asset.id, is_active: !asset.is_active })}
                    >
                      {asset.is_active ? 'Hide' : 'Show'}
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={async () => {
                        const ok = await confirm({
                          title: 'Remove this sign-in image?',
                          tone: 'danger',
                          confirmLabel: 'Remove image',
                          message: 'The file is deleted permanently. To take it off the sign-in screen without deleting it, use Hide instead.',
                          detail: `${asset.original_filename} · ${asset.size_label}`,
                        })

                        if (ok) remove.mutate(asset.id)
                      }}
                    >
                      Remove
                    </Button>
                  </div>
                </div>
              </figure>
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}

function UploadButton({
  type,
  label,
  onDone,
  onError,
}: {
  type: 'LOGO' | 'LOGIN_BACKGROUND'
  label: string
  onDone: (message: string) => void
  onError: (message: string) => void
}) {
  const inputRef = useRef<HTMLInputElement>(null)

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData()
      body.append('image', file)
      body.append('type', type)

      return (await api.post('/branding/assets', body)).data
    },
    onSuccess: (result: { message: string }) => onDone(result.message),
    onError: (err) => onError(errorMessage(err)),
  })

  return (
    <>
      <input
        ref={inputRef}
        type="file"
        accept={ACCEPTED}
        className="hidden"
        onChange={(e) => {
          const file = e.target.files?.[0]
          e.target.value = ''

          if (!file) return

          // Checked here as well as on the server, so someone who picks an
          // 8 MB photo is told at once instead of after the upload.
          if (file.size > MAX_BYTES) {
            onError(`That image is ${(file.size / 1024 / 1024).toFixed(1)} MB. The limit is 5 MB.`)
            return
          }

          upload.mutate(file)
        }}
      />
      <Button size="sm" variant="secondary" loading={upload.isPending} onClick={() => inputRef.current?.click()}>
        {label}
      </Button>
    </>
  )
}
