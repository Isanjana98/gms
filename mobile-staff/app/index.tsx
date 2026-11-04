import { Redirect } from 'expo-router'
import { useAuth } from '../src/lib/auth'

/**
 * The entry point decides only where to go.
 *
 * `_layout` has already waited for the session check, so by the time this
 * renders `user` is settled and the redirect is final.
 */
export default function Index() {
  const { user } = useAuth()

  return <Redirect href={user ? '/(tabs)' : '/sign-in'} />
}
