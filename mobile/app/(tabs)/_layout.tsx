import { Tabs } from 'expo-router'
import { Platform, Text } from 'react-native'
import { theme } from '../../src/lib/theme'

/**
 * Four tabs, and deliberately no more.
 *
 * A member opens this to answer one of four questions: when does my membership
 * run out, have I been coming, what have I paid, and what is on this week.
 * Anything else belongs at reception.
 *
 * The icons are emoji rather than an icon font: it keeps the bundle small and
 * avoids a native font dependency for six glyphs. Swap them for a proper icon
 * set if the app grows.
 */
export default function TabsLayout() {
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
          // Tall enough that the label is not clipped: 64 cut the descenders
          // off "Membership" on Android and on the web target.
          height: Platform.OS === 'ios' ? 88 : 72,
          paddingTop: 8,
          paddingBottom: Platform.OS === 'ios' ? 28 : 12,
        },
        tabBarLabelStyle: { fontSize: 11, fontWeight: '600' },
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'Membership',
          tabBarIcon: ({ color }) => <Text style={{ fontSize: 18, color }}>▣</Text>,
        }}
      />
      <Tabs.Screen
        name="visits"
        options={{
          title: 'Visits',
          tabBarIcon: ({ color }) => <Text style={{ fontSize: 18, color }}>◔</Text>,
        }}
      />
      <Tabs.Screen
        name="payments"
        options={{
          title: 'Payments',
          tabBarIcon: ({ color }) => <Text style={{ fontSize: 18, color }}>▤</Text>,
        }}
      />
      <Tabs.Screen
        name="timetable"
        options={{
          title: 'Classes',
          tabBarIcon: ({ color }) => <Text style={{ fontSize: 18, color }}>◈</Text>,
        }}
      />
    </Tabs>
  )
}
