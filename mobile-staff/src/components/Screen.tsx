// Copied from the member app (mobile/src/components/Screen.tsx). See src/lib/theme.ts for why the two
// apps keep their own copies of these primitives.
import type { ReactNode } from 'react'
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { theme } from '../lib/theme'
import { Muted, Title } from './ui'

/**
 * The frame every tab uses.
 *
 * Keeps the title, the side gutter and the pull-to-refresh consistent, so no
 * screen drifts in spacing and every screen can be refreshed the same way.
 * Pull-to-refresh matters more here than on the web: a member checking whether
 * reception has processed their renewal will pull, not hunt for a button.
 */
export function Screen({
  title,
  subtitle,
  children,
  onRefresh,
  refreshing = false,
  scroll = true,
  onBack,
}: {
  title: string
  subtitle?: string
  children: ReactNode
  onRefresh?: () => void
  refreshing?: boolean
  scroll?: boolean
  /**
   * Shown on a screen pushed over a tab. The native gesture still works; this
   * is for the web target, which has no swipe, and for thumbs that cannot
   * reach the top-left corner of a large phone.
   */
  onBack?: () => void
}) {
  const insets = useSafeAreaInsets()

  const header = (
    <View style={styles.header}>
      {onBack ? (
        <Pressable
          testID="back"
          onPress={onBack}
          accessibilityRole="button"
          accessibilityLabel="Go back"
          hitSlop={12}
          style={({ pressed }) => [styles.back, pressed && { opacity: 0.6 }]}
        >
          <Text style={styles.backText}>‹ Back</Text>
        </Pressable>
      ) : null}
      <Title>{title}</Title>
      {subtitle ? <Muted style={{ marginTop: 4 }}>{subtitle}</Muted> : null}
    </View>
  )

  if (!scroll) {
    return (
      <View style={[styles.screen, { paddingTop: insets.top + 16 }]}>
        {header}
        {children}
      </View>
    )
  }

  return (
    <ScrollView
      style={styles.screen}
      contentContainerStyle={{ paddingTop: insets.top + 16, paddingBottom: 32 }}
      refreshControl={
        onRefresh ? (
          <RefreshControl
            refreshing={refreshing}
            onRefresh={onRefresh}
            tintColor={theme.accent}
            colors={[theme.accent]}
            progressBackgroundColor={theme.surface}
          />
        ) : undefined
      }
    >
      {header}
      <View style={styles.body}>{children}</View>
    </ScrollView>
  )
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: theme.bg },
  header: { paddingHorizontal: theme.gutter, paddingBottom: 18 },
  back: { alignSelf: 'flex-start', marginBottom: 10 },
  backText: { color: theme.muted, fontSize: 15, fontWeight: '600' },
  body: { paddingHorizontal: theme.gutter, gap: 14 },
})
