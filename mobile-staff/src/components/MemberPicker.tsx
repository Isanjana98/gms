import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native'
import { api } from '../lib/api'
import { initials } from '../lib/format'
import { statusTone, theme } from '../lib/theme'
import type { MemberSummary, Paginated } from '../lib/types'
import { Button, Empty, Muted, Pill } from './ui'

/**
 * Choose a member.
 *
 * A plain `SelectField` cannot do this: a gym has thousands of members and
 * nobody scrolls to theirs. So it is a search, and it is the same search as
 * the check-in screen — name, membership number or phone — because staff
 * should not have to remember which screen accepts which.
 */
export function MemberPicker({
  value,
  onChange,
  testID = 'member-picker',
}: {
  value: MemberSummary | null
  onChange: (member: MemberSummary | null) => void
  testID?: string
}) {
  const [open, setOpen] = useState(false)
  const [term, setTerm] = useState('')

  const results = useQuery({
    queryKey: ['member-search', term],
    enabled: open && term.trim().length >= 2,
    queryFn: async () =>
      (
        await api.get<Paginated<MemberSummary>>('/members', {
          params: { search: term.trim(), per_page: 12 },
        })
      ).data.data,
  })

  return (
    <>
      <Pressable
        testID={testID}
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        style={({ pressed }) => [styles.field, pressed && { opacity: 0.65 }]}
      >
        {value ? (
          <>
            <View style={styles.avatar}>
              <Text style={styles.avatarText}>{initials(value.full_name)}</Text>
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.name}>{value.full_name}</Text>
              <Muted>{value.membership_number}</Muted>
            </View>
            <Text style={{ color: theme.dim, fontSize: 14 }}>Change</Text>
          </>
        ) : (
          <>
            <Text style={{ color: theme.dim, fontSize: 16, flex: 1 }}>Choose a member…</Text>
            <Text style={{ color: theme.dim, fontSize: 14 }}>▾</Text>
          </>
        )}
      </Pressable>

      <Modal visible={open} animationType="slide" transparent onRequestClose={() => setOpen(false)}>
        <View style={styles.backdrop}>
          <View style={styles.sheet}>
            <View style={{ padding: 16, paddingBottom: 10 }}>
              <TextInput
                testID="member-picker-search"
                value={term}
                onChangeText={setTerm}
                placeholder="Name, number or phone"
                placeholderTextColor={theme.dim}
                autoCapitalize="none"
                autoCorrect={false}
                autoFocus
                style={styles.search}
              />
            </View>

            <ScrollView style={{ maxHeight: 380 }}>
              {term.trim().length < 2 ? (
                <View style={{ padding: 20 }}>
                  <Empty title="Who is it for?" description="Type at least two characters." />
                </View>
              ) : results.isLoading ? (
                <ActivityIndicator color={theme.accent} style={{ paddingVertical: 28 }} />
              ) : !results.data?.length ? (
                <View style={{ padding: 20 }}>
                  <Empty title="Nobody found" description="Check the spelling, or try their phone number." />
                </View>
              ) : (
                results.data.map((member, index) => (
                  <Pressable
                    key={member.id}
                    testID={`member-option-${index}`}
                    onPress={() => {
                      onChange(member)
                      setOpen(false)
                      setTerm('')
                    }}
                    accessibilityRole="button"
                    style={({ pressed }) => [styles.option, pressed && { backgroundColor: theme.surface2 }]}
                  >
                    <View style={styles.avatar}>
                      <Text style={styles.avatarText}>{initials(member.full_name)}</Text>
                    </View>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.name}>{member.full_name}</Text>
                      <Muted>{member.membership_number}</Muted>
                    </View>
                    <Pill text={member.status.toLowerCase()} tone={statusTone(member.status)} />
                  </Pressable>
                ))
              )}
            </ScrollView>

            <View style={{ padding: 14 }}>
              <Button label="Cancel" variant="ghost" onPress={() => setOpen(false)} />
            </View>
          </View>
        </View>
      </Modal>
    </>
  )
}

const styles = StyleSheet.create({
  field: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    backgroundColor: theme.bg2,
    borderWidth: 1,
    borderColor: theme.lineStrong,
    borderRadius: 11,
    paddingHorizontal: 13,
    paddingVertical: 10,
    minHeight: 58,
  },
  name: { color: theme.text, fontSize: 15, fontWeight: '600' },

  avatar: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: theme.surface2,
    borderWidth: 1,
    borderColor: theme.line,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { color: theme.muted, fontWeight: '800', fontSize: 12 },

  backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.65)', justifyContent: 'flex-end' },
  sheet: {
    backgroundColor: theme.surface,
    borderTopLeftRadius: 20,
    borderTopRightRadius: 20,
    borderTopWidth: 1,
    borderColor: theme.lineStrong,
  },
  search: {
    backgroundColor: theme.bg2,
    borderWidth: 1,
    borderColor: theme.lineStrong,
    borderRadius: 11,
    color: theme.text,
    fontSize: 16,
    paddingHorizontal: 13,
    minHeight: 50,
  },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: 18,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
  },
})
