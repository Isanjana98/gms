import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Stack, useRouter, useSegments } from 'expo-router'
import { StatusBar } from 'expo-status-bar'
import { useEffect } from 'react'
import { View } from 'react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { Loading } from '../src/components/ui'
import { AuthProvider, useAuth } from '../src/lib/auth'
import { theme } from '../src/lib/theme'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // A membership expiry changes on a human timescale, and a member on
      // mobile data should not pay for a refetch every time the app returns
      // to the foreground.
      staleTime: 60_000,
      retry: (failureCount, error) => {
        const status = (error as { response?: { status?: number } })?.response?.status
        // Retrying a refusal just repeats it.
        if (status && status >= 400 && status < 500) return false
        return failureCount < 2
      },
    },
  },
})

/**
 * Sends the member to the right place once we know whether they are signed in.
 *
 * Done as a redirect after mount rather than by rendering one tree or the
 * other, because expo-router owns the navigation state: rendering a different
 * navigator on each auth state loses the history and breaks the Android back
 * button.
 */
function AuthGate() {
  const { member, loading } = useAuth()
  const segments = useSegments()
  const router = useRouter()

  useEffect(() => {
    if (loading) return

    const inApp = segments[0] === '(tabs)'

    if (!member && inApp) {
      router.replace('/sign-in')
    } else if (member && !inApp) {
      router.replace('/(tabs)')
    }
  }, [member, loading, segments, router])

  if (loading) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.bg }}>
        <Loading label="Checking your membership…" />
      </View>
    )
  }

  return (
    <Stack
      screenOptions={{
        headerShown: false,
        contentStyle: { backgroundColor: theme.bg },
        animation: 'fade',
      }}
    />
  )
}

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <StatusBar style="light" />
          <AuthGate />
        </AuthProvider>
      </QueryClientProvider>
    </SafeAreaProvider>
  )
}
