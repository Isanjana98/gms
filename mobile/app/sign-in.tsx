import { useRouter } from 'expo-router'
import { useRef, useState } from 'react'
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Body, Button, Card, Eyebrow, Muted, Title } from '../src/components/ui'
import { errorMessage } from '../src/lib/api'
import { useAuth } from '../src/lib/auth'
import { theme } from '../src/lib/theme'

type Step = 'identify' | 'code'

/**
 * Signing in, in two steps.
 *
 * There is no password anywhere in this flow — the member types something the
 * gym already has on file and then a code. See MemberAuthService on the server
 * for why.
 */
export default function SignIn() {
  const { requestCode, verify } = useAuth()
  const router = useRouter()
  const insets = useSafeAreaInsets()

  const [step, setStep] = useState<Step>('identify')
  const [identifier, setIdentifier] = useState('')
  const [code, setCode] = useState('')
  const [sentTo, setSentTo] = useState<string | null>(null)
  const [expiresIn, setExpiresIn] = useState(10)
  const [hint, setHint] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const codeInput = useRef<TextInput>(null)

  const send = async () => {
    if (!identifier.trim()) {
      setError('Enter your membership number, phone number or email.')
      return
    }

    setBusy(true)
    setError(null)

    try {
      const result = await requestCode(identifier)

      setSentTo(result.sentTo)
      setExpiresIn(result.expiresInMinutes)
      // Present only in local development, so the app can be used before an
      // SMS provider is configured. Never returned by a production server.
      setHint(result.debugCode ?? null)
      setStep('code')

      // A short delay so the step has rendered before the keyboard is asked
      // for; focusing an unmounted input silently does nothing.
      setTimeout(() => codeInput.current?.focus(), 120)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  const submit = async () => {
    if (code.trim().length !== 6) {
      setError('Enter the six-digit code.')
      return
    }

    setBusy(true)
    setError(null)

    try {
      await verify(identifier, code)
      router.replace('/(tabs)')
    } catch (err) {
      setError(errorMessage(err))
      setCode('')
    } finally {
      setBusy(false)
    }
  }

  const startAgain = () => {
    setStep('identify')
    setCode('')
    setError(null)
    setHint(null)
  }

  return (
    <KeyboardAvoidingView
      style={styles.screen}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <ScrollView
        contentContainerStyle={[styles.content, { paddingTop: insets.top + 48, paddingBottom: insets.bottom + 32 }]}
        keyboardShouldPersistTaps="handled"
      >
        <View style={styles.brand}>
          <View style={styles.mark}>
            <Text style={styles.markText}>G</Text>
          </View>
          <Title>Members</Title>
          <Muted style={{ marginTop: 6 }}>
            Your membership, your visits and your receipts.
          </Muted>
        </View>

        <Card style={{ marginTop: 32 }}>
          {step === 'identify' ? (
            <>
              <Eyebrow>Step 1 of 2</Eyebrow>
              <Body style={{ marginBottom: 18 }}>
                Enter your membership number, phone number or email. We will send you a code.
              </Body>

              <Text style={styles.label}>Membership number, phone or email</Text>
              <TextInput
                testID="identifier"
                value={identifier}
                onChangeText={setIdentifier}
                placeholder="GM-2026-00001"
                placeholderTextColor={theme.dim}
                autoCapitalize="characters"
                autoCorrect={false}
                style={styles.input}
                onSubmitEditing={send}
                returnKeyType="send"
              />

              {error ? <Text style={styles.error}>{error}</Text> : null}

              <Button label="Send code" onPress={send} loading={busy} style={{ marginTop: 18 }} />

              <Muted style={{ marginTop: 16 }}>
                No password needed. If we have no phone number or email on file for you, ask at reception.
              </Muted>
            </>
          ) : (
            <>
              <Eyebrow>Step 2 of 2</Eyebrow>
              <Body style={{ marginBottom: 18 }}>
                {sentTo
                  ? `We sent a code to ${sentTo}. It expires in ${expiresIn} minutes.`
                  : `If that matches a membership, a code is on its way. It expires in ${expiresIn} minutes.`}
              </Body>

              <Text style={styles.label}>Six-digit code</Text>
              <TextInput
                testID="code"
                ref={codeInput}
                value={code}
                onChangeText={(value) => setCode(value.replace(/\D/g, '').slice(0, 6))}
                placeholder="000000"
                placeholderTextColor={theme.dim}
                keyboardType="number-pad"
                inputMode="numeric"
                maxLength={6}
                // Lets iOS and Android offer the code straight from the SMS.
                textContentType="oneTimeCode"
                autoComplete="one-time-code"
                style={[styles.input, styles.codeInput]}
                onSubmitEditing={submit}
                returnKeyType="done"
              />

              {hint ? (
                <View style={styles.devHint}>
                  <Text style={styles.devHintText}>Development build — your code is {hint}</Text>
                </View>
              ) : null}

              {error ? <Text style={styles.error}>{error}</Text> : null}

              <Button label="Sign in" onPress={submit} loading={busy} style={{ marginTop: 18 }} />

              <Pressable onPress={startAgain} style={styles.link} accessibilityRole="button">
                <Text style={styles.linkText}>Use a different number</Text>
              </Pressable>
            </>
          )}
        </Card>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: theme.bg },
  content: { paddingHorizontal: theme.gutter, flexGrow: 1, justifyContent: 'center' },

  brand: { alignItems: 'center' },
  mark: {
    width: 62,
    height: 62,
    borderRadius: 16,
    backgroundColor: theme.accent,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 18,
  },
  markText: { color: '#fff', fontSize: 28, fontWeight: '900' },

  label: {
    color: theme.dim,
    fontSize: 11,
    fontWeight: '700',
    letterSpacing: 1.2,
    textTransform: 'uppercase',
    marginBottom: 8,
  },
  input: {
    backgroundColor: theme.bg2,
    borderWidth: 1,
    borderColor: theme.lineStrong,
    borderRadius: 12,
    color: theme.text,
    fontSize: 16,
    paddingHorizontal: 14,
    // A tap target a thumb can actually hit.
    minHeight: 50,
  },
  codeInput: {
    fontSize: 26,
    letterSpacing: 10,
    textAlign: 'center',
    fontWeight: '700',
  },

  error: { color: theme.accentHot, fontSize: 13, marginTop: 10 },

  devHint: {
    marginTop: 12,
    padding: 10,
    borderRadius: 10,
    backgroundColor: 'rgba(245,158,11,0.10)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.35)',
  },
  devHintText: { color: theme.warn, fontSize: 12, textAlign: 'center' },

  link: { marginTop: 16, alignItems: 'center', paddingVertical: 8 },
  linkText: { color: theme.muted, fontSize: 14 },
})
