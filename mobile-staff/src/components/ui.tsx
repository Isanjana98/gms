// Copied from the member app (mobile/src/components/ui.tsx). See src/lib/theme.ts for why the two
// apps keep their own copies of these primitives.
import type { ReactNode } from 'react'
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
  type StyleProp,
  type TextStyle,
  type ViewStyle,
} from 'react-native'
import { theme } from '../lib/theme'

/* ------------------------------------------------------------------- text */

export function Title({ children, style }: { children: ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[styles.title, style]}>{children}</Text>
}

export function Heading({ children, style }: { children: ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[styles.heading, style]}>{children}</Text>
}

export function Body({ children, style }: { children: ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[styles.body, style]}>{children}</Text>
}

export function Muted({ children, style }: { children: ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[styles.muted, style]}>{children}</Text>
}

/** The small red rule-and-caps label used above each section. */
export function Eyebrow({ children }: { children: ReactNode }) {
  return (
    <View style={styles.eyebrowRow}>
      <View style={styles.eyebrowRule} />
      <Text style={styles.eyebrowText}>{children}</Text>
    </View>
  )
}

/* ------------------------------------------------------------------- card */

export function Card({
  children,
  style,
}: {
  children: ReactNode
  style?: StyleProp<ViewStyle>
}) {
  return <View style={[styles.card, style]}>{children}</View>
}

/* ----------------------------------------------------------------- button */

export function Button({
  label,
  onPress,
  variant = 'primary',
  loading = false,
  disabled = false,
  style,
}: {
  label: string
  onPress: () => void
  variant?: 'primary' | 'ghost'
  loading?: boolean
  disabled?: boolean
  style?: StyleProp<ViewStyle>
}) {
  const inactive = disabled || loading

  return (
    <Pressable
      onPress={onPress}
      disabled={inactive}
      accessibilityRole="button"
      accessibilityState={{ disabled: inactive, busy: loading }}
      style={({ pressed }) => [
        styles.button,
        variant === 'primary' ? styles.buttonPrimary : styles.buttonGhost,
        // No hover on a phone, so pressed state is the only feedback there is.
        pressed && !inactive && styles.buttonPressed,
        inactive && styles.buttonDisabled,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={variant === 'primary' ? '#fff' : theme.text} />
      ) : (
        <Text style={[styles.buttonLabel, variant === 'ghost' && { color: theme.text }]}>{label}</Text>
      )}
    </Pressable>
  )
}

/* ----------------------------------------------------------------- states */

export function Loading({ label = 'Loading…' }: { label?: string }) {
  return (
    <View style={styles.centre}>
      <ActivityIndicator color={theme.accent} size="large" />
      <Muted style={{ marginTop: 12 }}>{label}</Muted>
    </View>
  )
}

export function Empty({ title, description }: { title: string; description?: string }) {
  return (
    <View style={styles.centre}>
      <Heading style={{ textAlign: 'center' }}>{title}</Heading>
      {description ? <Muted style={{ textAlign: 'center', marginTop: 6 }}>{description}</Muted> : null}
    </View>
  )
}

export function ErrorNotice({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={styles.centre}>
      <View style={styles.errorBox}>
        <Body style={{ color: '#FCA5A5' }}>{message}</Body>
      </View>
      {onRetry ? <Button label="Try again" variant="ghost" onPress={onRetry} style={{ marginTop: 14 }} /> : null}
    </View>
  )
}

/** A label/value row, used wherever the app lists facts. */
export function Fact({ label, value }: { label: string; value: ReactNode }) {
  return (
    <View style={styles.factRow}>
      <Text style={styles.factLabel}>{label}</Text>
      <Text style={styles.factValue}>{value}</Text>
    </View>
  )
}

export function Pill({ text, tone = theme.dim }: { text: string; tone?: string }) {
  return (
    <View style={[styles.pill, { borderColor: tone }]}>
      <Text style={[styles.pillText, { color: tone }]}>{text}</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  title: {
    color: theme.text,
    fontSize: 30,
    fontWeight: '800',
    letterSpacing: -0.6,
  },
  heading: {
    color: theme.text,
    fontSize: 17,
    fontWeight: '700',
    letterSpacing: -0.2,
  },
  body: {
    color: theme.muted,
    fontSize: 15,
    lineHeight: 22,
  },
  muted: {
    color: theme.dim,
    fontSize: 13,
    lineHeight: 19,
  },

  eyebrowRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginBottom: 10,
  },
  eyebrowRule: {
    width: 22,
    height: 2,
    backgroundColor: theme.accent,
  },
  eyebrowText: {
    color: theme.accentHot,
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 1.6,
    textTransform: 'uppercase',
  },

  card: {
    backgroundColor: theme.surface,
    borderRadius: theme.radius,
    borderWidth: 1,
    borderColor: theme.line,
    padding: 18,
  },

  button: {
    minHeight: 50,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 22,
    borderWidth: 1,
    borderColor: 'transparent',
  },
  buttonPrimary: { backgroundColor: theme.accent },
  buttonGhost: { backgroundColor: 'rgba(255,255,255,0.04)', borderColor: theme.lineStrong },
  buttonPressed: { opacity: 0.75, transform: [{ scale: 0.99 }] },
  buttonDisabled: { opacity: 0.5 },
  buttonLabel: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '800',
    letterSpacing: 1,
    textTransform: 'uppercase',
  },

  centre: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 32,
  },
  errorBox: {
    backgroundColor: 'rgba(225,29,46,0.10)',
    borderColor: 'rgba(225,29,46,0.35)',
    borderWidth: 1,
    borderRadius: 12,
    padding: 16,
  },

  factRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: 16,
    paddingVertical: 11,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
  },
  factLabel: {
    color: theme.dim,
    fontSize: 11,
    letterSpacing: 1,
    textTransform: 'uppercase',
    paddingTop: 2,
  },
  factValue: {
    color: theme.text,
    fontSize: 15,
    flexShrink: 1,
    textAlign: 'right',
  },

  pill: {
    borderWidth: 1,
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 3,
    alignSelf: 'flex-start',
  },
  pillText: {
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 0.8,
    textTransform: 'uppercase',
  },
})
