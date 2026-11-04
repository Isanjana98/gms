import { Redirect } from 'expo-router'
import { useAuth } from '../src/lib/auth'

/**
 * The entry point decides only where to go.
 *
 * `_layout` has already waited for the session check, so by the time this
 * renders `member` is settled and the redirect is final — no flash of the
 * sign-in screen for somebody who is signed in.
 */
export default function Index() {
  const { member } = useAuth()

  return <Redirect href={member ? '/(tabs)' : '/sign-in'} />
}
