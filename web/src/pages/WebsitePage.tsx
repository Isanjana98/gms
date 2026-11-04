import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
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
  cx,
} from '../components/ui'
import { useConfirm } from '../components/Confirm'
import { useToast } from '../components/Toast'
import { api, errorMessage, fieldErrors } from '../lib/api'
import { date } from '../lib/format'

type Tab = 'content' | 'images' | 'posts' | 'testimonials' | 'timetable'

interface Facility { title: string; description: string }
interface OpeningHour { days: string; hours: string }

interface WebsiteContent {
  enabled: boolean
  hero_headline: string
  hero_subtext: string
  hero_cta_label: string
  about_title: string
  about_body: string
  contact_note: string
  seo_title: string
  seo_description: string
  map_embed: string
  facilities: Facility[]
  opening_hours: OpeningHour[]
  social: Record<string, string>
  show_pricing: boolean
  show_trainers: boolean
  show_gallery: boolean
  show_blog: boolean
  show_timetable: boolean
  show_testimonials: boolean
}

/**
 * The public website, edited from here.
 *
 * Only what the system cannot already know. Prices come from Membership
 * Plans, the team from Trainers, the address from Branches, the name and logo
 * from Branding — the website reads all of those live, so none of them appear
 * on this screen to be typed a second time and drift.
 */
export function WebsitePage() {
  const [tab, setTab] = useState<Tab>('content')

  const { data, isLoading, error } = useQuery({
    queryKey: ['website'],
    queryFn: async () =>
      (await api.get<{ content: WebsiteContent; url: string; counts: Record<string, number> }>('/website')).data,
  })

  const tabs: Array<{ key: Tab; label: string; count?: number }> = [
    { key: 'content', label: 'Content' },
    { key: 'images', label: 'Images' },
    { key: 'posts', label: 'News', count: data?.counts.posts },
    { key: 'testimonials', label: 'Testimonials', count: data?.counts.testimonials },
    { key: 'timetable', label: 'Timetable', count: data?.counts.timetable },
  ]

  return (
    <>
      <PageHeader
        title="Website"
        subtitle="Your public website — what visitors see before they join"
        actions={
          data?.url && (
            <a className="inline-flex items-center gap-1.5 rounded-lg bg-white px-3.5 py-2 text-sm font-medium text-ink-700 ring-1 ring-inset ring-ink-300 hover:bg-ink-50"
               href={data.url} target="_blank" rel="noreferrer">
              View website ↗
            </a>
          )
        }
      />

      {error ? (
        <Card><Alert>{errorMessage(error)}</Alert></Card>
      ) : isLoading || !data ? (
        <LoadingBlock />
      ) : (
        <>
          {!data.content.enabled && (
            <div className="mb-5">
              <Alert tone="warning">
                Your website is not published. Visitors see a "not available" page until you switch it on below.
              </Alert>
            </div>
          )}

          <div className="mb-5 flex flex-wrap gap-1 rounded-lg border border-ink-200 bg-white p-1">
            {tabs.map((item) => (
              <button
                key={item.key}
                type="button"
                onClick={() => setTab(item.key)}
                className={cx(
                  'rounded px-3 py-1.5 text-sm font-medium transition',
                  tab === item.key ? 'bg-brand-600 text-white' : 'text-ink-600 hover:bg-ink-100',
                )}
              >
                {item.label}
                {item.count != null && item.count > 0 && (
                  <span className={cx('ml-1.5 text-xs', tab === item.key ? 'text-white/70' : 'text-ink-400')}>
                    {item.count}
                  </span>
                )}
              </button>
            ))}
          </div>

          {tab === 'content' && <ContentTab content={data.content} />}
          {tab === 'images' && <ImagesTab />}
          {tab === 'posts' && <PostsTab />}
          {tab === 'testimonials' && <TestimonialsTab />}
          {tab === 'timetable' && <TimetableTab />}
        </>
      )}
    </>
  )
}

/* ------------------------------------------------------------------ content */

function ContentTab({ content }: { content: WebsiteContent }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()

  const [form, setForm] = useState<WebsiteContent>(content)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  // Re-seeded when the query refetches, so a save elsewhere does not leave
  // this form showing stale values.
  useEffect(() => setForm(content), [content])

  const save = useMutation({
    mutationFn: async (payload: Partial<WebsiteContent>) => (await api.put('/website', payload)).data,
    onSuccess: (result: { message: string }) => {
      toast.success(result.message)
      setError(null)
      setErrors({})
      queryClient.invalidateQueries({ queryKey: ['website'] })
    },
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  const set = <K extends keyof WebsiteContent>(key: K, value: WebsiteContent[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }))

  const submit = (event: FormEvent) => {
    event.preventDefault()
    const { enabled, ...rest } = form
    save.mutate(rest)
  }

  /** Publishing puts the gym's name on the open internet — worth one question. */
  const togglePublished = async () => {
    if (!form.enabled) {
      const ok = await confirm({
        title: 'Publish your website?',
        confirmLabel: 'Publish',
        message: <>Anyone with the address will be able to see your website, and search engines can begin listing it.</>,
        detail: <>Check the content on this page first. You can take it offline again at any time.</>,
      })

      if (!ok) return
    }

    save.mutate({ enabled: !form.enabled })
    set('enabled', !form.enabled)
  }

  return (
    <div className="space-y-6">
      <Card title="Publish">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p className="text-sm font-medium text-ink-900">
              {form.enabled ? 'Your website is live' : 'Your website is offline'}
            </p>
            <p className="text-sm text-ink-500">
              {form.enabled
                ? 'Visitors can see it now.'
                : 'Visitors see a "not available" page. Nothing is indexed by search engines.'}
            </p>
          </div>
          <Button variant={form.enabled ? 'secondary' : 'primary'} onClick={togglePublished}>
            {form.enabled ? 'Take offline' : 'Publish website'}
          </Button>
        </div>
      </Card>

      <form onSubmit={submit} className="space-y-6">
        {error && <Alert>{error}</Alert>}

        <Card title="Front page" subtitle="The first thing a visitor reads">
          <div className="space-y-4">
            <Field label="Headline" required error={errors.hero_headline}>
              <Input value={form.hero_headline} onChange={(e) => set('hero_headline', e.target.value)} />
            </Field>
            <Field label="Supporting line" error={errors.hero_subtext}>
              <Textarea value={form.hero_subtext} onChange={(e) => set('hero_subtext', e.target.value)} />
            </Field>
            <Field label="Button text" error={errors.hero_cta_label}>
              <Input value={form.hero_cta_label} onChange={(e) => set('hero_cta_label', e.target.value)} />
            </Field>
          </div>
        </Card>

        <Card title="About page">
          <div className="space-y-4">
            <Field label="Heading" error={errors.about_title}>
              <Input value={form.about_title} onChange={(e) => set('about_title', e.target.value)} />
            </Field>
            <Field label="Text" error={errors.about_body} hint="Leave a blank line between paragraphs.">
              <Textarea
                className="min-h-48"
                value={form.about_body}
                onChange={(e) => set('about_body', e.target.value)}
              />
            </Field>
            <Field label="Invitation to visit" error={errors.contact_note}>
              <Input value={form.contact_note} onChange={(e) => set('contact_note', e.target.value)} />
            </Field>
          </div>
        </Card>

        <Card title="Facilities" subtitle="What the gym offers, shown as cards">
          <RepeatingRows
            rows={form.facilities}
            onChange={(rows) => set('facilities', rows)}
            blank={{ title: '', description: '' }}
            max={12}
            addLabel="Add facility"
            render={(row, update) => (
              <>
                <Input placeholder="Free weights floor" value={row.title} onChange={(e) => update({ ...row, title: e.target.value })} />
                <Input placeholder="Olympic platforms, racks and dumbbells." value={row.description} onChange={(e) => update({ ...row, description: e.target.value })} />
              </>
            )}
          />
        </Card>

        <Card title="Opening hours" subtitle="Shown in the footer and on the contact page">
          <RepeatingRows
            rows={form.opening_hours}
            onChange={(rows) => set('opening_hours', rows)}
            blank={{ days: '', hours: '' }}
            max={10}
            addLabel="Add hours"
            render={(row, update) => (
              <>
                <Input placeholder="Monday – Friday" value={row.days} onChange={(e) => update({ ...row, days: e.target.value })} />
                <Input placeholder="5:00am – 10:00pm" value={row.hours} onChange={(e) => update({ ...row, hours: e.target.value })} />
              </>
            )}
          />
        </Card>

        <Card title="Social media">
          <div className="grid gap-4 sm:grid-cols-2">
            {['facebook', 'instagram', 'tiktok', 'youtube'].map((network) => (
              <Field key={network} label={network[0].toUpperCase() + network.slice(1)} error={errors[`social.${network}`]}>
                <Input
                  type="url"
                  placeholder={`https://${network}.com/yourgym`}
                  value={form.social[network] ?? ''}
                  onChange={(e) => set('social', { ...form.social, [network]: e.target.value })}
                />
              </Field>
            ))}
          </div>
        </Card>

        <Card title="Pages" subtitle="Switch off anything you are not ready to show">
          <div className="grid gap-3 sm:grid-cols-2">
            {(
              [
                ['show_pricing', 'Membership prices'],
                ['show_trainers', 'Trainers'],
                ['show_gallery', 'Photo gallery'],
                ['show_timetable', 'Class timetable'],
                ['show_blog', 'News'],
                ['show_testimonials', 'Testimonials'],
              ] as const
            ).map(([key, label]) => (
              <label key={key} className="flex items-center gap-2 text-sm text-ink-700">
                <input
                  type="checkbox"
                  checked={form[key]}
                  onChange={(e) => set(key, e.target.checked)}
                  className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
                />
                {label}
              </label>
            ))}
          </div>
          <p className="mt-3 text-xs text-ink-500">
            A page is only shown when it is switched on and has something in it — an empty gallery is hidden either way.
          </p>
        </Card>

        <Card title="Search engines" subtitle="How your gym appears in search results">
          <div className="space-y-4">
            <Field label="Title" error={errors.seo_title} hint="Around 60 characters. Defaults to your gym's name.">
              <Input value={form.seo_title} onChange={(e) => set('seo_title', e.target.value)} />
            </Field>
            <Field
              label="Description"
              error={errors.seo_description}
              hint="The sentence under the title in search results. Around 155 characters."
            >
              <Textarea value={form.seo_description} onChange={(e) => set('seo_description', e.target.value)} />
            </Field>
            <Field label="Map embed URL" error={errors.map_embed} hint="From Google Maps → Share → Embed a map → copy the src URL.">
              <Input type="url" value={form.map_embed} onChange={(e) => set('map_embed', e.target.value)} />
            </Field>
          </div>
        </Card>

        <div className="flex justify-end">
          <Button type="submit" loading={save.isPending}>
            Save content
          </Button>
        </div>
      </form>
    </div>
  )
}

/**
 * A list of identical rows that can be added to and removed from.
 *
 * Facilities and opening hours are the same shape of problem, and writing the
 * add/remove handling twice is how the two end up behaving differently.
 */
function RepeatingRows<T>({
  rows,
  onChange,
  blank,
  render,
  addLabel,
  max,
}: {
  rows: T[]
  onChange: (rows: T[]) => void
  blank: T
  render: (row: T, update: (next: T) => void) => React.ReactNode
  addLabel: string
  max: number
}) {
  return (
    <div className="space-y-3">
      {rows.length === 0 && <p className="text-sm text-ink-500">Nothing added yet.</p>}

      {rows.map((row, index) => (
        <div key={index} className="flex flex-col gap-2 sm:flex-row sm:items-start">
          <div className="grid flex-1 gap-2 sm:grid-cols-2">
            {render(row, (next) => onChange(rows.map((r, i) => (i === index ? next : r))))}
          </div>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={() => onChange(rows.filter((_, i) => i !== index))}
          >
            Remove
          </Button>
        </div>
      ))}

      {rows.length < max && (
        <Button type="button" size="sm" variant="secondary" onClick={() => onChange([...rows, blank])}>
          {addLabel}
        </Button>
      )}
    </div>
  )
}

/* ------------------------------------------------------------------- images */

interface Asset {
  id: number
  type: string
  url: string
  caption: string | null
  is_active: boolean
  size_label: string
}

function ImagesTab() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [busy, setBusy] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['branding-assets'],
    queryFn: async () => (await api.get<{ data: Asset[] }>('/branding/assets')).data.data,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['branding-assets'] })

  const upload = async (file: File, type: string) => {
    setBusy(true)

    try {
      const body = new FormData()
      body.append('image', file)
      body.append('type', type)

      await api.post('/branding/assets', body)
      toast.success('Image added.')
      refresh()
    } catch (err) {
      toast.error('Could not upload the image', errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  const remove = async (asset: Asset) => {
    const ok = await confirm({
      title: 'Delete this image?',
      tone: 'danger',
      confirmLabel: 'Delete image',
      message: <>It will be removed from the website and deleted from the server.</>,
    })

    if (!ok) return

    try {
      await api.delete(`/branding/assets/${asset.id}`)
      toast.success('Image deleted.')
      refresh()
    } catch (err) {
      toast.error('Could not delete the image', errorMessage(err))
    }
  }

  const groups: Array<{ type: string; title: string; hint: string }> = [
    { type: 'HERO', title: 'Front page banner', hint: 'The large image behind your headline. Falls back to your sign-in images if empty.' },
    { type: 'GALLERY', title: 'Gallery', hint: 'Photographs of the gym, shown on the front page and the gallery page.' },
  ]

  if (isLoading) return <LoadingBlock />

  return (
    <div className="space-y-6">
      {groups.map((group) => {
        const images = (data ?? []).filter((asset) => asset.type === group.type)

        return (
          <Card
            key={group.type}
            title={group.title}
            subtitle={group.hint}
            actions={
              <label className={cx('cursor-pointer', busy && 'pointer-events-none opacity-60')}>
                <span className="inline-flex min-h-[36px] items-center rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-brand-700">
                  Add image
                </span>
                <input
                  type="file"
                  accept="image/*"
                  className="sr-only"
                  onChange={(e) => {
                    const file = e.target.files?.[0]
                    if (file) upload(file, group.type)
                    e.target.value = ''
                  }}
                />
              </label>
            }
          >
            {images.length === 0 ? (
              <EmptyState title="No images yet" description="Upload a photograph to show it on the website." />
            ) : (
              <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {images.map((asset) => (
                  <figure key={asset.id} className="overflow-hidden rounded-lg ring-1 ring-ink-200">
                    <img src={asset.url} alt={asset.caption ?? ''} className="aspect-[4/3] w-full object-cover" />
                    <figcaption className="flex items-center justify-between gap-2 p-2">
                      <span className="truncate text-xs text-ink-500">{asset.size_label}</span>
                      <Button size="sm" variant="ghost" onClick={() => remove(asset)}>
                        Delete
                      </Button>
                    </figcaption>
                  </figure>
                ))}
              </div>
            )}
          </Card>
        )
      })}
    </div>
  )
}

/* -------------------------------------------------------------------- posts */

interface Post {
  id: number
  title: string
  slug: string
  excerpt: string | null
  body: string
  is_published: boolean
  published_at: string | null
  seo_title: string | null
  seo_description: string | null
  author: string | null
  url: string
}

function PostsTab() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [editing, setEditing] = useState<Post | null>(null)
  const [creating, setCreating] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['website-posts'],
    queryFn: async () => (await api.get<{ data: Post[] }>('/website/posts')).data.data,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['website-posts'] })
    queryClient.invalidateQueries({ queryKey: ['website'] })
  }

  const remove = async (post: Post) => {
    const ok = await confirm({
      title: 'Delete this post?',
      tone: 'danger',
      confirmLabel: 'Delete post',
      message: <><strong>{post.title}</strong> will be removed from the website.</>,
    })

    if (!ok) return

    try {
      await api.delete(`/website/posts/${post.id}`)
      toast.success('Post deleted.')
      refresh()
    } catch (err) {
      toast.error('Could not delete the post', errorMessage(err))
    }
  }

  return (
    <>
      <Card
        title="News"
        subtitle="Articles on your website"
        padded={false}
        actions={<Button onClick={() => setCreating(true)}>Write a post</Button>}
      >
        {isLoading ? (
          <LoadingBlock />
        ) : !data?.length ? (
          <EmptyState
            title="Nothing written yet"
            description="Posts are a good way to be found in local searches — but only if somebody keeps writing them."
            action={<Button onClick={() => setCreating(true)}>Write a post</Button>}
          />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Title</Th>
                <Th>Published</Th>
                <Th>Author</Th>
                <Th align="right">Actions</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {data.map((post) => (
                <tr key={post.id} className={post.is_published ? '' : 'opacity-70'}>
                  <Td>
                    <span className="font-medium text-ink-900">{post.title}</span>
                    <span className="block text-xs text-ink-500">/blog/{post.slug}</span>
                  </Td>
                  <Td>
                    {post.is_published ? (
                      <>
                        <Badge tone="success">live</Badge>
                        <span className="block text-xs text-ink-500">{date(post.published_at)}</span>
                      </>
                    ) : (
                      <Badge>draft</Badge>
                    )}
                  </Td>
                  <Td>{post.author ?? '—'}</Td>
                  <Td align="right">
                    <div className="flex justify-end gap-1.5">
                      {post.is_published && (
                        <a
                          className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-ink-600 hover:bg-ink-100"
                          href={post.url}
                          target="_blank"
                          rel="noreferrer"
                        >
                          View
                        </a>
                      )}
                      <Button size="sm" variant="ghost" onClick={() => setEditing(post)}>
                        Edit
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => remove(post)}>
                        Delete
                      </Button>
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {(creating || editing) && (
        <PostModal
          post={editing}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
          onSaved={(message) => {
            toast.success(message)
            setCreating(false)
            setEditing(null)
            refresh()
          }}
        />
      )}
    </>
  )
}

function PostModal({
  post,
  onClose,
  onSaved,
}: {
  post: Post | null
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const [form, setForm] = useState({
    title: post?.title ?? '',
    excerpt: post?.excerpt ?? '',
    body: post?.body ?? '',
    is_published: post?.is_published ?? false,
    seo_description: post?.seo_description ?? '',
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: async () =>
      post
        ? (await api.put(`/website/posts/${post.id}`, form)).data
        : (await api.post('/website/posts', form)).data,
    onSuccess: (result: { message: string }) => onSaved(result.message),
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={post ? 'Edit post' : 'Write a post'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button form="post-form" type="submit" loading={save.isPending}>
            Save
          </Button>
        </>
      }
    >
      <form
        id="post-form"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
        className="space-y-4"
      >
        {error && <Alert>{error}</Alert>}

        <Field label="Title" required error={errors.title}>
          <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required />
        </Field>

        <Field label="Summary" error={errors.excerpt} hint="One or two lines, shown on the news page.">
          <Textarea value={form.excerpt} onChange={(e) => setForm({ ...form, excerpt: e.target.value })} />
        </Field>

        <Field label="Body" required error={errors.body} hint="Leave a blank line between paragraphs.">
          <Textarea
            className="min-h-64"
            value={form.body}
            onChange={(e) => setForm({ ...form, body: e.target.value })}
            required
          />
        </Field>

        <Field label="Search description" error={errors.seo_description}>
          <Input value={form.seo_description} onChange={(e) => setForm({ ...form, seo_description: e.target.value })} />
        </Field>

        <label className="flex items-center gap-2 text-sm text-ink-700">
          <input
            type="checkbox"
            checked={form.is_published}
            onChange={(e) => setForm({ ...form, is_published: e.target.checked })}
            className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
          />
          Publish this post
        </label>

        <p className="text-xs text-ink-500">
          Unpublished posts are not reachable on the website, even by their address.
        </p>
      </form>
    </Modal>
  )
}

/* ------------------------------------------------------------- testimonials */

interface TestimonialRow {
  id: number
  name: string
  role: string | null
  quote: string
  rating: number | null
  is_active: boolean
  sort_order: number
}

function TestimonialsTab() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [editing, setEditing] = useState<TestimonialRow | null>(null)
  const [creating, setCreating] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['website-testimonials'],
    queryFn: async () => (await api.get<{ data: TestimonialRow[] }>('/website/testimonials')).data.data,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['website-testimonials'] })
    queryClient.invalidateQueries({ queryKey: ['website'] })
  }

  const remove = async (row: TestimonialRow) => {
    const ok = await confirm({
      title: 'Delete this testimonial?',
      tone: 'danger',
      confirmLabel: 'Delete',
      message: <>The quote from <strong>{row.name}</strong> will be removed from the website.</>,
    })

    if (!ok) return

    try {
      await api.delete(`/website/testimonials/${row.id}`)
      toast.success('Testimonial deleted.')
      refresh()
    } catch (err) {
      toast.error('Could not delete the testimonial', errorMessage(err))
    }
  }

  return (
    <>
      <Card
        title="Testimonials"
        subtitle="Member quotes shown on your front page"
        padded={false}
        actions={<Button onClick={() => setCreating(true)}>Add testimonial</Button>}
      >
        {isLoading ? (
          <LoadingBlock />
        ) : !data?.length ? (
          <EmptyState
            title="No testimonials yet"
            description="Ask a few long-standing members for a sentence about training here."
            action={<Button onClick={() => setCreating(true)}>Add testimonial</Button>}
          />
        ) : (
          <ul className="divide-y divide-ink-100">
            {data.map((row) => (
              <li key={row.id} className={cx('px-5 py-4', !row.is_active && 'opacity-60')}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <p className="wrap-anywhere text-sm text-ink-800">“{row.quote}”</p>
                    <p className="mt-1 text-xs text-ink-500">
                      {row.name}
                      {row.role ? ` · ${row.role}` : ''}
                      {row.rating ? ` · ${row.rating}★` : ''}
                      {!row.is_active && ' · hidden'}
                    </p>
                  </div>
                  <div className="flex gap-1.5">
                    <Button size="sm" variant="ghost" onClick={() => setEditing(row)}>Edit</Button>
                    <Button size="sm" variant="ghost" onClick={() => remove(row)}>Delete</Button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {(creating || editing) && (
        <TestimonialModal
          row={editing}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
          onSaved={(message) => {
            toast.success(message)
            setCreating(false)
            setEditing(null)
            refresh()
          }}
        />
      )}
    </>
  )
}

function TestimonialModal({
  row,
  onClose,
  onSaved,
}: {
  row: TestimonialRow | null
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const [form, setForm] = useState({
    name: row?.name ?? '',
    role: row?.role ?? '',
    quote: row?.quote ?? '',
    rating: String(row?.rating ?? ''),
    is_active: row?.is_active ?? true,
    sort_order: String(row?.sort_order ?? 0),
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        rating: form.rating ? Number(form.rating) : null,
        sort_order: Number(form.sort_order || 0),
      }

      return row
        ? (await api.put(`/website/testimonials/${row.id}`, payload)).data
        : (await api.post('/website/testimonials', payload)).data
    },
    onSuccess: (result: { message: string }) => onSaved(result.message),
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={row ? 'Edit testimonial' : 'Add testimonial'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button form="testimonial-form" type="submit" loading={save.isPending}>Save</Button>
        </>
      }
    >
      <form
        id="testimonial-form"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
        className="space-y-4"
      >
        {error && <Alert>{error}</Alert>}

        <Field label="Quote" required error={errors.quote}>
          <Textarea value={form.quote} onChange={(e) => setForm({ ...form, quote: e.target.value })} required />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Name" required error={errors.name}>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          </Field>
          <Field label="Description" error={errors.role} hint="e.g. Member since 2019">
            <Input value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} />
          </Field>
          <Field label="Rating" error={errors.rating}>
            <Select value={form.rating} onChange={(e) => setForm({ ...form, rating: e.target.value })}>
              <option value="">No rating</option>
              {[5, 4, 3, 2, 1].map((n) => (
                <option key={n} value={n}>{n} star{n === 1 ? '' : 's'}</option>
              ))}
            </Select>
          </Field>
          <Field label="Order" error={errors.sort_order} hint="Lower numbers appear first.">
            <Input type="number" min="0" value={form.sort_order} onChange={(e) => setForm({ ...form, sort_order: e.target.value })} />
          </Field>
        </div>

        <label className="flex items-center gap-2 text-sm text-ink-700">
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
            className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
          />
          Show on the website
        </label>

        <p className="text-xs text-ink-500">
          Publish a member's words only with their permission.
        </p>
      </form>
    </Modal>
  )
}

/* ---------------------------------------------------------------- timetable */

interface Entry {
  id: number
  class_name: string
  description: string | null
  day_of_week: number
  day_name: string
  start_time: string
  end_time: string
  room: string | null
  trainer_id: number | null
  instructor: string | null
  is_active: boolean
}

function TimetableTab() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [editing, setEditing] = useState<Entry | null>(null)
  const [creating, setCreating] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['website-timetable'],
    queryFn: async () =>
      (await api.get<{ data: Entry[]; days: Record<string, string> }>('/website/timetable')).data,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['website-timetable'] })
    queryClient.invalidateQueries({ queryKey: ['website'] })
  }

  const remove = async (entry: Entry) => {
    const ok = await confirm({
      title: 'Remove this class?',
      tone: 'danger',
      confirmLabel: 'Remove class',
      message: <><strong>{entry.class_name}</strong> on {entry.day_name} will be removed from the timetable.</>,
    })

    if (!ok) return

    try {
      await api.delete(`/website/timetable/${entry.id}`)
      toast.success('Class removed.')
      refresh()
    } catch (err) {
      toast.error('Could not remove the class', errorMessage(err))
    }
  }

  return (
    <>
      <Card
        title="Class timetable"
        subtitle="Published on the website. This is a printed timetable, not a booking system — there is nothing for members to reserve."
        padded={false}
        actions={<Button onClick={() => setCreating(true)}>Add class</Button>}
      >
        {isLoading ? (
          <LoadingBlock />
        ) : !data?.data?.length ? (
          <EmptyState
            title="No classes yet"
            description="Add the classes you run each week and they will appear on the website."
            action={<Button onClick={() => setCreating(true)}>Add class</Button>}
          />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Day</Th>
                <Th>Time</Th>
                <Th>Class</Th>
                <Th>Instructor</Th>
                <Th>Room</Th>
                <Th align="right">Actions</Th>
              </tr>
            </thead>
            <tbody className="divide-y divide-ink-100">
              {data.data.map((entry) => (
                <tr key={entry.id} className={entry.is_active ? '' : 'opacity-60'}>
                  <Td>{entry.day_name}</Td>
                  <Td>
                    <span className="tabular">{entry.start_time} – {entry.end_time}</span>
                  </Td>
                  <Td>
                    <span className="font-medium">{entry.class_name}</span>
                    {!entry.is_active && <span className="ml-1.5"><Badge>hidden</Badge></span>}
                  </Td>
                  <Td>{entry.instructor ?? '—'}</Td>
                  <Td>{entry.room ?? '—'}</Td>
                  <Td align="right">
                    <div className="flex justify-end gap-1.5">
                      <Button size="sm" variant="ghost" onClick={() => setEditing(entry)}>Edit</Button>
                      <Button size="sm" variant="ghost" onClick={() => remove(entry)}>Remove</Button>
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {(creating || editing) && (
        <TimetableModal
          entry={editing}
          days={data?.days ?? {}}
          onClose={() => {
            setCreating(false)
            setEditing(null)
          }}
          onSaved={(message) => {
            toast.success(message)
            setCreating(false)
            setEditing(null)
            refresh()
          }}
        />
      )}
    </>
  )
}

function TimetableModal({
  entry,
  days,
  onClose,
  onSaved,
}: {
  entry: Entry | null
  days: Record<string, string>
  onClose: () => void
  onSaved: (message: string) => void
}) {
  const [form, setForm] = useState({
    class_name: entry?.class_name ?? '',
    description: entry?.description ?? '',
    day_of_week: String(entry?.day_of_week ?? 1),
    start_time: entry?.start_time ?? '18:00',
    end_time: entry?.end_time ?? '19:00',
    room: entry?.room ?? '',
    trainer_id: String(entry?.trainer_id ?? ''),
    instructor_name: entry?.instructor && !entry?.trainer_id ? entry.instructor : '',
    is_active: entry?.is_active ?? true,
  })
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const { data: trainers } = useQuery({
    queryKey: ['trainers-list'],
    queryFn: async () => (await api.get<{ data: Array<{ id: number; full_name: string }> }>('/trainers')).data.data,
  })

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        day_of_week: Number(form.day_of_week),
        trainer_id: form.trainer_id ? Number(form.trainer_id) : null,
      }

      return entry
        ? (await api.put(`/website/timetable/${entry.id}`, payload)).data
        : (await api.post('/website/timetable', payload)).data
    },
    onSuccess: (result: { message: string }) => onSaved(result.message),
    onError: (err) => {
      setError(errorMessage(err))
      setErrors(fieldErrors(err))
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={entry ? 'Edit class' : 'Add class'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button form="timetable-form" type="submit" loading={save.isPending}>Save</Button>
        </>
      }
    >
      <form
        id="timetable-form"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
        className="space-y-4"
      >
        {error && <Alert>{error}</Alert>}

        <Field label="Class name" required error={errors.class_name}>
          <Input value={form.class_name} onChange={(e) => setForm({ ...form, class_name: e.target.value })} placeholder="Spin" required />
        </Field>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Day" required error={errors.day_of_week}>
            <Select value={form.day_of_week} onChange={(e) => setForm({ ...form, day_of_week: e.target.value })}>
              {Object.entries(days).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </Select>
          </Field>
          <Field label="Starts" required error={errors.start_time}>
            <Input type="time" value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} required />
          </Field>
          <Field label="Ends" required error={errors.end_time}>
            <Input type="time" value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} required />
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Instructor" error={errors.trainer_id}>
            <Select value={form.trainer_id} onChange={(e) => setForm({ ...form, trainer_id: e.target.value })}>
              <option value="">Not set</option>
              {(trainers ?? []).map((trainer) => (
                <option key={trainer.id} value={trainer.id}>{trainer.full_name}</option>
              ))}
            </Select>
          </Field>
          <Field label="Room" error={errors.room}>
            <Input value={form.room} onChange={(e) => setForm({ ...form, room: e.target.value })} placeholder="Studio 1" />
          </Field>
        </div>

        <Field
          label="Guest instructor"
          error={errors.instructor_name}
          hint="For someone who is not on your trainer list. Ignored if a trainer is chosen above."
        >
          <Input value={form.instructor_name} onChange={(e) => setForm({ ...form, instructor_name: e.target.value })} />
        </Field>

        <label className="flex items-center gap-2 text-sm text-ink-700">
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
            className="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-600"
          />
          Show on the website
        </label>
      </form>
    </Modal>
  )
}
