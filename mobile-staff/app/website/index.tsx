import { useQuery } from '@tanstack/react-query'
import { useRouter } from 'expo-router'
import { useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import { Screen } from '../../src/components/Screen'
import { FormRow, FormSection, NotPermitted, Outcome, SwitchField, TextField } from '../../src/components/form'
import { Button, Card, Eyebrow, Muted } from '../../src/components/ui'
import { api } from '../../src/lib/api'
import { P, useAuth } from '../../src/lib/auth'
import { saveError, useAction } from '../../src/lib/resource'
import { theme } from '../../src/lib/theme'

type Content = Record<string, string | boolean | null>

/**
 * The public website's words.
 *
 * Only the text that changes often is editable here — the headline, the
 * opening hours, the phone number people ring. Photography, layout and section
 * ordering stay in the admin panel: choosing a hero image on a 400px screen is
 * choosing it blind.
 */
export default function Website() {
  const { can } = useAuth()
  const router = useRouter()

  const [edits, setEdits] = useState<Content>({})
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)

  const site = useQuery({
    queryKey: ['/website'],
    enabled: can(P.websiteManage),
    queryFn: async () => (await api.get<{ content: Content }>('/website')).data.content,
  })

  const save = useAction({ invalidate: ['/website'] })

  if (!can(P.websiteManage)) {
    return (
      <Screen title="Website" onBack={() => router.back()}>
        <NotPermitted what="manage the website" />
      </Screen>
    )
  }

  const content = site.data ?? {}
  const value = (key: string) => (edits[key] ?? content[key] ?? '') as string
  const changed = Object.keys(edits).length

  const field = (key: string, label: string, hint?: string, multiline = false) => (
    <FormRow label={label} hint={hint}>
      <TextField
        testID={`site-${key}`}
        value={value(key)}
        onChange={(v) => setEdits((e) => ({ ...e, [key]: v }))}
        multiline={multiline}
      />
    </FormRow>
  )

  return (
    <Screen
      title="Website"
      subtitle={content.enabled ? 'Published' : 'Not published'}
      onBack={() => router.back()}
      onRefresh={() => void site.refetch()}
      refreshing={site.isRefetching}
    >
      {site.isLoading ? <Muted>Loading…</Muted> : null}
      {site.error ? <Outcome ok={false} message={saveError(site.error)} /> : null}

      <Card>
        <SwitchField
          label="Published"
          hint="Off takes the public site down and leaves everything here intact."
          value={Boolean(edits.enabled ?? content.enabled)}
          onChange={(v) => setEdits((e) => ({ ...e, enabled: v }))}
        />
      </Card>

      <FormSection title="The first thing people read">
        {field('hero_headline', 'Headline')}
        {field('hero_subtext', 'Underneath it', undefined, true)}
        {field('hero_cta_label', 'Button', 'What the join button says.')}
      </FormSection>

      <FormSection title="About">
        {field('about_title', 'Title')}
        {field('about_body', 'Body', undefined, true)}
      </FormSection>

      <FormSection title="How to reach you">
        {field('contact_telephone', 'Telephone')}
        {field('contact_email', 'Email')}
        {field('contact_address', 'Address', undefined, true)}
        {field('opening_hours', 'Opening hours', 'Shown on every page.', true)}
      </FormSection>

      {result ? <Outcome ok={result.ok} message={result.message} /> : null}

      {changed > 0 ? (
        <View style={{ gap: 10 }}>
          <Button
            label={`Save ${changed} change${changed === 1 ? '' : 's'}`}
            loading={save.isPending}
            onPress={() => {
              setResult(null)
              save.mutate(
                { url: '/website', method: 'put', payload: { content: { ...content, ...edits } } },
                {
                  onSuccess: () => {
                    setResult({ ok: true, message: 'The website has been updated.' })
                    setEdits({})
                    void site.refetch()
                  },
                  onError: (error) => setResult({ ok: false, message: saveError(error) }),
                },
              )
            }}
          />
          <Button label="Discard changes" variant="ghost" onPress={() => setEdits({})} />
        </View>
      ) : null}

      <View style={{ gap: 10 }}>
        <Eyebrow>The rest of the site</Eyebrow>
        <Card style={{ padding: 0 }}>
          <Link label="News" hint="Posts on the website" onPress={() => router.push('/website/posts')} />
          <Link
            label="Testimonials"
            hint="What members say"
            onPress={() => router.push('/website/testimonials')}
          />
          <Link
            label="Class timetable"
            hint="What runs, and when"
            onPress={() => router.push('/website/timetable')}
            last
          />
        </Card>
      </View>

      <Muted>
        Photographs, section order and the gallery stay in the admin panel: those are choices you have to see
        full size to make.
      </Muted>
    </Screen>
  )
}

function Link({
  label,
  hint,
  onPress,
  last = false,
}: {
  label: string
  hint: string
  onPress: () => void
  last?: boolean
}) {
  return (
    <Button
      label={`${label}  ·  ${hint}`}
      variant="ghost"
      onPress={onPress}
      style={[styles.link, last && { borderBottomWidth: 0 }]}
    />
  )
}

const styles = StyleSheet.create({
  link: {
    borderRadius: 0,
    borderWidth: 0,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
    backgroundColor: 'transparent',
  },
})
