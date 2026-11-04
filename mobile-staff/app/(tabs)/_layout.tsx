import { Tabs } from 'expo-router'
import { Platform, Text, type ColorValue } from 'react-native'
import { P, useAuth } from '../../src/lib/auth'
import { theme } from '../../src/lib/theme'

/**
 * Five tabs, and a door to everything else.
 *
 * Check-in stays first and stays the default: it is the only thing this app
 * does that a desktop cannot do better, and it is what somebody standing at
 * the door needs in one tap.
 *
 * The rest of the admin panel lives under **More** rather than in the bar,
 * because a tab bar stops being navigation somewhere around six items and
 * starts being a puzzle. What earns a tab is what gets used during a shift —
 * the door, a member lookup, today's numbers, taking money. Reports did not:
 * it is read once a week, usually sitting down, and it is one tap away.
 *
 * A tab whose permission the user lacks is not rendered at all. `href: null`
 * rather than a conditional child: expo-router builds its route table from the
 * files present, and a tab removed from the tree still leaves a reachable
 * route, so hiding it in the bar alone would leave a back-door URL on the web
 * target.
 */
export default function TabsLayout() {
  const { can } = useAuth()

  // ColorValue, not string: the tab bar passes a platform colour object on
  // some builds, and typing it as a string compiles here but not on CI.
  const icon = (glyph: string) => {
    const Icon = ({ color }: { color: ColorValue }) => (
      <Text style={{ fontSize: 18, color }}>{glyph}</Text>
    )
    Icon.displayName = `TabIcon(${glyph})`
    return Icon
  }

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: theme.accentHot,
        tabBarInactiveTintColor: theme.dim,
        tabBarStyle: {
          backgroundColor: theme.bg2,
          borderTopColor: theme.line,
          // Android draws its own elevation shadow, which reads as a grey
          // smear on a near-black bar.
          elevation: 0,
          height: Platform.OS === 'ios' ? 88 : 72,
          paddingTop: 8,
          paddingBottom: Platform.OS === 'ios' ? 28 : 12,
        },
        tabBarLabelStyle: { fontSize: 11, fontWeight: '600' },
      }}
    >
      <Tabs.Screen
        name="index"
        options={{ title: 'Check-in', tabBarIcon: icon('◉') }}
      />
      <Tabs.Screen
        name="members"
        options={{
          title: 'Members',
          tabBarIcon: icon('▤'),
          href: can(P.membersView) ? undefined : null,
        }}
      />
      <Tabs.Screen
        name="today"
        options={{ title: 'Today', tabBarIcon: icon('▣') }}
      />
      <Tabs.Screen
        name="money"
        options={{
          title: 'Money',
          tabBarIcon: icon('₨'),
          href: can(P.paymentsView) || can(P.expensesView) ? undefined : null,
        }}
      />
      <Tabs.Screen
        name="more"
        options={{ title: 'More', tabBarIcon: icon('⋯') }}
      />
    </Tabs>
  )
}
