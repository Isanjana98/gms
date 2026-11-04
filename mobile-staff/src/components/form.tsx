import { useState, type ReactNode } from 'react'
import {
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  View,
} from 'react-native'
import { theme } from '../lib/theme'
import { Body, Button, Card, Eyebrow, Muted } from './ui'

/**
 * Form controls for the staff app.
 *
 * The admin panel can spread a form across a screen and lean on a mouse. A
 * phone cannot, so these are built for the opposite constraints: one control
 * per row, targets big enough for a thumb, and no control that depends on
 * hovering or on a keyboard that is not there.
 *
 * Nothing here uses `Alert` or a native picker. React Native Web implements
 * neither, and the whole app is driven through Expo's web target during
 * verification — a control that silently does nothing in a browser is a
 * control that cannot be tested.
 */

export function FormRow({
  label,
  hint,
  error,
  children,
}: {
  label: string
  hint?: string
  error?: string
  children: ReactNode
}) {
  return (
    <View style={styles.row}>
      <Text style={styles.label}>{label}</Text>
      {children}
      {error ? <Text style={styles.error}>{error}</Text> : hint ? <Muted>{hint}</Muted> : null}
    </View>
  )
}

export function TextField({
  value,
  onChange,
  placeholder,
  multiline = false,
  keyboardType = 'default',
  autoCapitalize = 'sentences',
  secure = false,
  testID,
}: {
  value: string
  onChange: (v: string) => void
  placeholder?: string
  multiline?: boolean
  keyboardType?: 'default' | 'numeric' | 'email-address' | 'phone-pad' | 'decimal-pad'
  autoCapitalize?: 'none' | 'sentences' | 'words' | 'characters'
  secure?: boolean
  testID?: string
}) {
  return (
    <TextInput
      testID={testID}
      value={value}
      onChangeText={onChange}
      placeholder={placeholder}
      placeholderTextColor={theme.dim}
      multiline={multiline}
      keyboardType={keyboardType}
      autoCapitalize={autoCapitalize}
      secureTextEntry={secure}
      autoCorrect={false}
      style={[styles.input, multiline && styles.inputMultiline]}
    />
  )
}

/**
 * Money, typed on a phone keypad.
 *
 * Kept as a string rather than a number while it is being edited: a half-typed
 * "12." is not a number, and coercing it every keystroke moves the caret and
 * eats the decimal point the moment it is pressed.
 */
export function MoneyField({
  value,
  onChange,
  testID,
}: {
  value: string
  onChange: (v: string) => void
  testID?: string
}) {
  return (
    <View style={styles.moneyWrap}>
      <Text style={styles.moneyPrefix}>LKR</Text>
      <TextInput
        testID={testID}
        value={value}
        onChangeText={(v) => onChange(v.replace(/[^0-9.]/g, ''))}
        placeholder="0.00"
        placeholderTextColor={theme.dim}
        keyboardType="decimal-pad"
        style={[styles.input, styles.moneyInput]}
      />
    </View>
  )
}

export type Option = { label: string; value: string | number; hint?: string }

/**
 * A picker that is a list, not a wheel.
 *
 * A full-screen sheet of ordinary rows works identically on iOS, Android and
 * the web target, and reading twenty membership plans is easier in a list than
 * through a three-line wheel.
 */
export function SelectField({
  value,
  options,
  onChange,
  placeholder = 'Choose…',
  testID,
}: {
  value: string | number | null | undefined
  options: Option[]
  onChange: (v: string | number) => void
  placeholder?: string
  testID?: string
}) {
  const [open, setOpen] = useState(false)
  const chosen = options.find((o) => String(o.value) === String(value))

  return (
    <>
      <Pressable
        testID={testID}
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        style={({ pressed }) => [styles.input, styles.select, pressed && styles.pressed]}
      >
        <Text style={{ color: chosen ? theme.text : theme.dim, fontSize: 16, flex: 1 }}>
          {chosen?.label ?? placeholder}
        </Text>
        <Text style={{ color: theme.dim, fontSize: 14 }}>▾</Text>
      </Pressable>

      <Modal visible={open} animationType="slide" transparent onRequestClose={() => setOpen(false)}>
        <View style={styles.sheetBackdrop}>
          <View style={styles.sheet}>
            <ScrollView style={{ maxHeight: 420 }}>
              {options.length === 0 ? (
                <View style={{ padding: 20 }}>
                  <Muted>Nothing to choose from yet.</Muted>
                </View>
              ) : (
                options.map((option, index) => (
                  <Pressable
                    key={String(option.value)}
                    testID={`option-${index}`}
                    onPress={() => {
                      onChange(option.value)
                      setOpen(false)
                    }}
                    accessibilityRole="button"
                    style={({ pressed }) => [styles.option, pressed && styles.pressed]}
                  >
                    <View style={{ flex: 1 }}>
                      <Text style={{ color: theme.text, fontSize: 16 }}>{option.label}</Text>
                      {option.hint ? <Muted>{option.hint}</Muted> : null}
                    </View>
                    {String(option.value) === String(value) ? (
                      <Text style={{ color: theme.accentHot, fontSize: 16 }}>✓</Text>
                    ) : null}
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

/**
 * A date, typed.
 *
 * No native date picker: the community picker is a third dependency that
 * renders differently on each platform and not at all on the web target. A
 * plain ISO field with a "Today" shortcut is duller and works everywhere, and
 * reception is usually copying a date off a form anyway.
 */
export function DateField({
  value,
  onChange,
  testID,
}: {
  value: string
  onChange: (v: string) => void
  testID?: string
}) {
  return (
    <View style={{ flexDirection: 'row', gap: 8 }}>
      <View style={{ flex: 1 }}>
        <TextField
          testID={testID}
          value={value}
          onChange={onChange}
          placeholder="YYYY-MM-DD"
          keyboardType="numeric"
          autoCapitalize="none"
        />
      </View>
      <Pressable
        onPress={() => onChange(new Date().toISOString().slice(0, 10))}
        accessibilityRole="button"
        style={({ pressed }) => [styles.today, pressed && styles.pressed]}
      >
        <Text style={{ color: theme.muted, fontSize: 13, fontWeight: '600' }}>Today</Text>
      </Pressable>
    </View>
  )
}

export function SwitchField({
  value,
  onChange,
  label,
  hint,
}: {
  value: boolean
  onChange: (v: boolean) => void
  label: string
  hint?: string
}) {
  return (
    <View style={styles.switchRow}>
      <View style={{ flex: 1 }}>
        <Text style={styles.label}>{label}</Text>
        {hint ? <Muted>{hint}</Muted> : null}
      </View>
      <Switch
        value={value}
        onValueChange={onChange}
        trackColor={{ false: theme.surface2, true: 'rgba(225,29,46,0.55)' }}
        thumbColor={value ? theme.accentHot : theme.dim}
      />
    </View>
  )
}

/**
 * What happened after a save.
 *
 * Sits where the eye already is — directly above the button that was just
 * pressed — rather than as a toast at the top of a scrolled form, which on a
 * phone is frequently off-screen.
 */
export function Outcome({ ok, message }: { ok: boolean; message: string }) {
  return (
    <View style={[styles.outcome, ok ? styles.outcomeOk : styles.outcomeBad]}>
      <Text style={{ color: ok ? theme.good : theme.accentHot, fontSize: 15 }}>{message}</Text>
    </View>
  )
}

/** A titled group of fields. */
export function FormSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card>
      <Eyebrow>{title}</Eyebrow>
      <View style={{ gap: 16, marginTop: 4 }}>{children}</View>
    </Card>
  )
}

/** Shown in place of a form when the signed-in user may not use it. */
export function NotPermitted({ what }: { what: string }) {
  return (
    <Card>
      <Body>You do not have permission to {what}.</Body>
      <Muted>Ask an administrator if you need it.</Muted>
    </Card>
  )
}

const styles = StyleSheet.create({
  row: { gap: 7 },
  label: { color: theme.text, fontSize: 14, fontWeight: '600' },
  error: { color: theme.accentHot, fontSize: 13 },

  input: {
    backgroundColor: theme.bg2,
    borderWidth: 1,
    borderColor: theme.lineStrong,
    borderRadius: 11,
    color: theme.text,
    fontSize: 16,
    paddingHorizontal: 13,
    // minHeight rather than padding: an Android TextInput ignores vertical
    // padding once it has a value, and the text jumps as you type.
    minHeight: 50,
    ...Platform.select({ web: { paddingVertical: 12 }, default: {} }),
  },
  inputMultiline: { minHeight: 104, paddingTop: 12, textAlignVertical: 'top' },

  select: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  pressed: { opacity: 0.65 },

  moneyWrap: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  moneyPrefix: { color: theme.muted, fontSize: 13, fontWeight: '700', letterSpacing: 0.5 },
  moneyInput: { flex: 1 },

  today: {
    paddingHorizontal: 14,
    justifyContent: 'center',
    borderRadius: 11,
    borderWidth: 1,
    borderColor: theme.line,
    backgroundColor: theme.surface2,
  },

  switchRow: { flexDirection: 'row', alignItems: 'center', gap: 14 },

  sheetBackdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.65)', justifyContent: 'flex-end' },
  sheet: {
    backgroundColor: theme.surface,
    borderTopLeftRadius: 20,
    borderTopRightRadius: 20,
    borderTopWidth: 1,
    borderColor: theme.lineStrong,
    paddingTop: 8,
  },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: 20,
    paddingVertical: 15,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
  },

  outcome: { padding: 14, borderRadius: 10, borderWidth: 1 },
  outcomeOk: { backgroundColor: 'rgba(16,185,129,0.10)', borderColor: 'rgba(16,185,129,0.35)' },
  outcomeBad: { backgroundColor: 'rgba(225,29,46,0.10)', borderColor: 'rgba(225,29,46,0.35)' },
})
