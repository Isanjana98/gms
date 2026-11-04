import { useRouter } from 'expo-router'
import { useState } from 'react'
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

/**
 * Staff sign-in.
 *
 * Email and password, unlike the member app's one-time code. Staff sign in to
 * a tool they use every shift and already have credentials for; sending an SMS
 * to open the check-in desk would be an obstacle, not a protection.
 */
export default function SignIn() {
  const { signIn } = useAuth()
  const router = useRouter()
  const insets = useSafeAreaInsets()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    if (!email.trim() || !password) {
      setError('Enter your email address and password.')
      return
    }

    setBusy(true)
    setError(null)

    try {
      await signIn(email, password)
      router.replace('/(tabs)')
    } catch (err) {
      setError(errorMessage(err))
      setPassword('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <KeyboardAvoidingView style={styles.screen} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView
        contentContainerStyle={[
          styles.content,
          { paddingTop: insets.top + 48, paddingBottom: insets.bottom + 32 },
        ]}
        keyboardShouldPersistTaps="handled"
      >
        <View style={styles.brand}>
          <View style={styles.mark}>
            <Text style={styles.markText}>G</Text>
          </View>
          <Title>Staff</Title>
          <Muted style={{ marginTop: 6 }}>Check-in desk, members and today's numbers.</Muted>
        </View>

        <Card style={{ marginTop: 32 }}>
          <Eyebrow>Sign in</Eyebrow>
          <Body style={{ marginBottom: 18 }}>Use the same details as the admin panel.</Body>

          <Text style={styles.label}>Email address</Text>
          <TextInput
            testID="email"
            value={email}
            onChangeText={setEmail}
            placeholder="you@gym.local"
            placeholderTextColor={theme.dim}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            textContentType="emailAddress"
            style={styles.input}
          />

          <Text style={[styles.label, { marginTop: 14 }]}>Password</Text>
          <View>
            <TextInput
              testID="password"
              value={password}
              onChangeText={setPassword}
              placeholder="••••••••"
              placeholderTextColor={theme.dim}
              secureTextEntry={!showPassword}
              autoCapitalize="none"
              autoCorrect={false}
              textContentType="password"
              style={[styles.input, { paddingRight: 68 }]}
              onSubmitEditing={submit}
              returnKeyType="go"
            />
            <Pressable
              onPress={() => setShowPassword((shown) => !shown)}
              style={styles.reveal}
              accessibilityRole="button"
              accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
            >
              <Text style={styles.revealText}>{showPassword ? 'Hide' : 'Show'}</Text>
            </Pressable>
          </View>

          {error ? <Text style={styles.error}>{error}</Text> : null}

          <Button label="Sign in" onPress={submit} loading={busy} style={{ marginTop: 20 }} />

          <Muted style={{ marginTop: 16 }}>
            This device holds member details. Sign out when you hand it over.
          </Muted>
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
    minHeight: 50,
  },
  reveal: {
    position: 'absolute',
    right: 4,
    top: 0,
    bottom: 0,
    paddingHorizontal: 14,
    justifyContent: 'center',
  },
  revealText: { color: theme.muted, fontSize: 13, fontWeight: '600' },

  error: { color: theme.accentHot, fontSize: 13, marginTop: 12 },
})
