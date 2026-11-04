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
      /*
       * Much shorter than the member app's minute.
       *
       * This is a desk tool: "who is in the gym right now" is worthless if it
       * is a minute old, and somebody checking a member in expects the next
       * screen to reflect it.
       */
      staleTime: 10_000,
      retry: (failureCount, error) => {
        const status = (error as { response?: { status?: number } })?.response?.status
        if (status && status >= 400 && status < 500) return false
        return failureCount < 2
      },
    },
  },
})

function AuthGate() {
  const { user, loading } = useAuth()
  const segments = useSegments()
  const router = useRouter()

  useEffect(() => {
    if (loading) return

    /*
     * Signed in, the whole app is reachable — not just the tabs.
     *
     * An earlier version sent anything outside `(tabs)` back to the tabs,
     * which was fine while the app was three tabs and nothing else. Once the
     * modules became pushed screens it meant tapping "Payments" bounced
     * straight back, and the only thing that must be guarded is the sign-in
     * screen itself.
     */
    const onSignIn = segments[0] === 'sign-in'

    if (!user && !onSignIn) {
      router.replace('/sign-in')
    } else if (user && onSignIn) {
      router.replace('/(tabs)')
    }
  }, [user, loading, segments, router])

  if (loading) {
    return (
      <View style={{ flex: 1, backgroundColor: theme.bg }}>
        <Loading label="Checking your session…" />
      </View>
    )
  }

  return (
    <Stack
      screenOptions={{
        headerShown: false,
        contentStyle: { backgroundColor: theme.bg },
        // Pushed modules slide; the tab shell itself is swapped by the gate
        // above, where a slide would look like a screen the user navigated to.
        animation: 'slide_from_right',
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
