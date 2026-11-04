import { useRouter } from 'expo-router'
import { useState } from 'react'
import { Pressable, StyleSheet, Text, View } from 'react-native'
import { ConfirmSheet } from '../../src/components/ConfirmSheet'
import { Screen } from '../../src/components/Screen'
import { Body, Button, Card, Eyebrow, Muted, Pill } from '../../src/components/ui'
import { useAuth } from '../../src/lib/auth'
import { MODULE_GROUPS, modulesFor, type Module } from '../../src/lib/modules'
import { theme } from '../../src/lib/theme'

/**
 * Everything the app can do that is not a tab.
 *
 * Grouped the way somebody thinks about a gym — people, money, training, the
 * gym itself, administration — rather than the way the API is organised. A
 * receptionist and an owner open this screen to different lists, and neither
 * is shown a row that would dead-end in a 403.
 */
export default function More() {
  const { user, can, signOut } = useAuth()
  const router = useRouter()
  const [confirming, setConfirming] = useState(false)

  const available = modulesFor(can)
  const groups = MODULE_GROUPS.map((group) => ({
    group,
    modules: available.filter((m) => m.group === group),
  })).filter((g) => g.modules.length > 0)

  return (
    <Screen title="More" subtitle={user?.name ?? undefined}>
      <Card>
        <Eyebrow>Signed in as</Eyebrow>
        <View style={styles.who}>
          <View style={{ flex: 1 }}>
            <Text style={styles.name}>{user?.name}</Text>
            <Muted>{user?.email}</Muted>
          </View>
        </View>
        <View style={styles.roles}>
          {(user?.roles ?? []).map((role) => (
            <Pill key={role} text={role} tone={theme.muted} />
          ))}
        </View>
        {user?.branch ? <Muted>{user.branch.name}</Muted> : user?.is_multi_branch ? <Muted>All branches</Muted> : null}
      </Card>

      {groups.map(({ group, modules }) => (
        <View key={group} style={{ gap: 10 }}>
          <Eyebrow>{group}</Eyebrow>
          <Card style={{ padding: 0 }}>
            {modules.map((module, index) => (
              <ModuleRow
                key={module.key}
                module={module}
                last={index === modules.length - 1}
                onPress={() => router.push(module.route as never)}
              />
            ))}
          </Card>
        </View>
      ))}

      {groups.length === 0 ? (
        <Card>
          <Body>There is nothing else here for your account.</Body>
          <Muted>Your permissions cover the check-in desk only.</Muted>
        </Card>
      ) : null}

      <Button label="Sign out" variant="ghost" onPress={() => setConfirming(true)} />

      <ConfirmSheet
        visible={confirming}
        title="Sign out?"
        message="This device holds member details and the gym's takings. Signing out clears them."
        confirmLabel="Sign out"
        onConfirm={() => {
          setConfirming(false)
          void signOut()
        }}
        onCancel={() => setConfirming(false)}
      />
    </Screen>
  )
}

function ModuleRow({
  module,
  last,
  onPress,
}: {
  module: Module
  last: boolean
  onPress: () => void
}) {
  return (
    <Pressable
      testID={`module-${module.key}`}
      onPress={onPress}
      accessibilityRole="button"
      style={({ pressed }) => [styles.row, last && styles.rowLast, pressed && styles.pressed]}
    >
      <View style={styles.glyph}>
        <Text style={{ color: theme.muted, fontSize: 16 }}>{module.icon}</Text>
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.rowTitle}>{module.title}</Text>
        <Muted>{module.description}</Muted>
      </View>
      <Text style={{ color: theme.dim, fontSize: 18 }}>›</Text>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  who: { flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 12 },
  name: { color: theme.text, fontSize: 17, fontWeight: '700' },
  roles: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginBottom: 10 },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 13,
    paddingHorizontal: 16,
    paddingVertical: 13,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
  },
  rowLast: { borderBottomWidth: 0 },
  pressed: { backgroundColor: theme.surface2 },
  rowTitle: { color: theme.text, fontSize: 15, fontWeight: '600' },

  glyph: {
    width: 36,
    height: 36,
    borderRadius: 10,
    backgroundColor: theme.surface2,
    borderWidth: 1,
    borderColor: theme.line,
    alignItems: 'center',
    justifyContent: 'center',
  },
})
