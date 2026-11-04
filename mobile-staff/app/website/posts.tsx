import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { ResourceList, Row } from '../../src/components/ResourceList'
import { Screen } from '../../src/components/Screen'
import { FormRow, FormSection, NotPermitted, Outcome, SwitchField, TextField } from '../../src/components/form'
import { Button, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { formatDate } from '../../src/lib/format'
import { fieldErrors, saveError, useAction, useSaveResource } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'

type Post = {
  id: number
  title: string
  slug: string
  excerpt?: string | null
  body?: string | null
  is_published?: boolean
  published_at?: string | null
}

/** News on the public website. */
export default function Posts() {
  const { can } = useAuth()
  const router = useRouter()

  const [editing, setEditing] = useState<Post | 'new' | null>(null)
  const [title, setTitle] = useState('')
  const [excerpt, setExcerpt] = useState('')
  const [body, setBody] = useState('')
  const [published, setPublished] = useState(true)
  const [removing, setRemoving] = useState<Post | null>(null)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const posts = useQuery({
    queryKey: ['/website/posts'],
    enabled: can(P.websiteManage),
    queryFn: async () => {
      const { data } = await api.get<{ data: Post[] } | Post[]>('/website/posts')
      return Array.isArray(data) ? data : data.data
    },
  })

  const existing = editing && editing !== 'new' ? editing : undefined
  const save = useSaveResource('/website/posts', { id: existing?.id })
  const remove = useAction({ invalidate: ['/website/posts'] })
  const errors = fieldErrors(save.error)

  if (!can(P.websiteManage)) {
    return (
      <Screen title="News" onBack={() => router.back()}>
        <NotPermitted what="manage the website" />
      </Screen>
    )
  }

  const open = (post: Post | 'new') => {
    setResult(null)
    setEditing(post)
    setTitle(post === 'new' ? '' : post.title)
    setExcerpt(post === 'new' ? '' : (post.excerpt ?? ''))
    setBody(post === 'new' ? '' : (post.body ?? ''))
    setPublished(post === 'new' ? true : (post.is_published ?? true))
  }

  const submit = () => {
    setResult(null)
    save.mutate(
      {
        title: title.trim(),
        excerpt: excerpt.trim() || undefined,
        body: body.trim() || undefined,
        is_published: published,
      },
      {
        onSuccess: () => {
          setResult({ ok: true, message: existing ? 'Post updated.' : 'Post published.' })
          setEditing(null)
          void posts.refetch()
        },
        onError: (error) => setResult({ ok: false, message: saveError(error) }),
      },
    )
  }

  if (editing) {
    return (
      <Screen
        title={existing ? 'Edit post' : 'New post'}
        subtitle={existing?.slug}
        onBack={() => setEditing(null)}
      >
        <FormSection title="The post">
          <FormRow label="Title" error={errors.title}>
            <TextField testID="post-title" value={title} onChange={setTitle} />
          </FormRow>
          <FormRow label="Summary" hint="Shown on the news list." error={errors.excerpt}>
            <TextField value={excerpt} onChange={setExcerpt} multiline />
          </FormRow>
          <FormRow label="Body" error={errors.body}>
            <TextField value={body} onChange={setBody} multiline />
          </FormRow>
          <SwitchField
            label="Published"
            hint="Off keeps it as a draft, visible only here."
            value={published}
            onChange={setPublished}
          />
        </FormSection>

        {result ? <Outcome ok={result.ok} message={result.message} /> : null}

        <Button label={existing ? 'Save changes' : 'Publish'} onPress={submit} loading={save.isPending} />
        {existing ? (
          <Button label="Delete this post" variant="ghost" onPress={() => setRemoving(existing)} />
        ) : null}

        <ConfirmSheet
          visible={Boolean(removing)}
          title="Delete this post?"
          message="It comes off the website immediately."
          confirmLabel="Delete"
          onCancel={() => setRemoving(null)}
          onConfirm={() => {
            const target = removing
            setRemoving(null)
            if (!target) return

            remove.mutate(
              { url: `/website/posts/${target.id}`, method: 'delete' },
              {
                onSuccess: () => {
                  setEditing(null)
                  void posts.refetch()
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
      title="News"
      subtitle={`${posts.data?.length ?? 0} posts`}
      onBack={() => router.back()}
      onRefresh={() => void posts.refetch()}
      refreshing={posts.isRefetching}
    >
      <Button label="Write a post" onPress={() => open('new')} />

      <ResourceList
        rows={posts.data ?? []}
        loading={posts.isLoading}
        error={posts.error ? saveError(posts.error) : undefined}
        onRetry={() => void posts.refetch()}
        emptyTitle="No posts"
        emptyDescription="Nothing has been written yet."
        onSelect={open}
        renderRow={(row) => (
          <Row
            title={row.title}
            subtitle={row.excerpt ?? row.slug}
            trailing={row.published_at ? formatDate(row.published_at) : undefined}
            status={row.is_published === false ? 'draft' : undefined}
            statusTone={theme.warn}
          />
        )}
      />

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      <Muted>Posts appear on the website newest first.</Muted>
    </Screen>
  )
}
