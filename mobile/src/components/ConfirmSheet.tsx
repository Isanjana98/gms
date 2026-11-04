import { Modal, Pressable, StyleSheet, View } from 'react-native'
import { theme } from '../lib/theme'
import { Body, Button, Heading } from './ui'

/**
 * A confirmation the app draws itself.
 *
 * Not `Alert.alert`. React Native Web does not implement it with buttons, so
 * on the web target — which is how this app is reviewed and tested — the
 * confirm silently never resolved and the action never happened. A component
 * behaves the same on every platform, can be styled to match the rest of the
 * app, and can be driven by a test.
 *
 * Used only for the few things worth asking about. Confirming ordinary taps
 * teaches people to dismiss dialogs without reading them.
 */
export function ConfirmSheet({
  visible,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  onConfirm,
  onCancel,
}: {
  visible: boolean
  title: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  onConfirm: () => void
  onCancel: () => void
}) {
  return (
    <Modal
      visible={visible}
      transparent
      animationType="fade"
      // Android's hardware back must dismiss it, or the sheet is a trap.
      onRequestClose={onCancel}
    >
      <Pressable style={styles.backdrop} onPress={onCancel} accessibilityLabel="Dismiss">
        {/* Taps inside the sheet must not fall through to the backdrop. */}
        <Pressable style={styles.sheet} onPress={() => {}}>
          <Heading>{title}</Heading>
          <Body style={{ marginTop: 8 }}>{message}</Body>

          <View style={styles.actions}>
            <Button label={cancelLabel} variant="ghost" onPress={onCancel} style={{ flex: 1 }} />
            <Button label={confirmLabel} onPress={onConfirm} style={{ flex: 1 }} />
          </View>
        </Pressable>
      </Pressable>
    </Modal>
  )
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(4,4,6,0.8)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
  },
  sheet: {
    width: '100%',
    maxWidth: 420,
    backgroundColor: theme.surface,
    borderRadius: theme.radius,
    borderWidth: 1,
    borderColor: theme.lineStrong,
    padding: 20,
  },
  actions: { flexDirection: 'row', gap: 10, marginTop: 22 },
})
