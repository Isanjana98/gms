import type { ReactNode } from 'react'
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native'
import { theme } from '../lib/theme'
import { Body, Button, Card, Empty, Muted, Pill } from './ui'

/**
 * The list half of every module.
 *
 * Search, rows, paging and the four states a list can be in — loading, failed,
 * empty, full — written once. A module screen supplies only `renderRow`, which
 * is the part that actually differs.
 */

export function SearchBar({
  value,
  onChange,
  placeholder,
  testID = 'search',
}: {
  value: string
  onChange: (v: string) => void
  placeholder: string
  testID?: string
}) {
  return (
    <TextInput
      testID={testID}
      value={value}
      onChangeText={onChange}
      placeholder={placeholder}
      placeholderTextColor={theme.dim}
      autoCapitalize="none"
      autoCorrect={false}
      style={styles.search}
    />
  )
}

export function ResourceList<T extends { id: number | string }>({
  rows,
  loading,
  error,
  onRetry,
  emptyTitle,
  emptyDescription,
  renderRow,
  onSelect,
  page,
  lastPage,
  total,
  onPage,
}: {
  rows: T[]
  loading: boolean
  error?: string
  onRetry?: () => void
  emptyTitle: string
  emptyDescription?: string
  renderRow: (row: T) => ReactNode
  onSelect?: (row: T) => void
  page?: number
  lastPage?: number
  total?: number
  onPage?: (page: number) => void
}) {
  if (error) {
    return (
      <Card>
        <Body style={{ color: theme.accentHot }}>{error}</Body>
        {onRetry ? <Button label="Try again" variant="ghost" onPress={onRetry} style={{ marginTop: 14 }} /> : null}
      </Card>
    )
  }

  // Only a first load gets a spinner. A reload keeps the old rows on screen,
  // because replacing a list the user is reading with a spinner loses their
  // place for no benefit.
  if (loading && rows.length === 0) {
    return (
      <Card>
        <ActivityIndicator color={theme.accent} style={{ paddingVertical: 26 }} />
      </Card>
    )
  }

  if (rows.length === 0) {
    return (
      <Card>
        <Empty title={emptyTitle} description={emptyDescription} />
      </Card>
    )
  }

  return (
    <>
      <Card style={{ padding: 0 }}>
        {rows.map((row, index) => {
          const content = (
            <View style={[styles.row, index === rows.length - 1 && styles.rowLast]}>
              {renderRow(row)}
            </View>
          )

          if (!onSelect) return <View key={String(row.id)}>{content}</View>

          return (
            <Pressable
              key={String(row.id)}
              testID={`result-${index}`}
              onPress={() => onSelect(row)}
              accessibilityRole="button"
              style={({ pressed }) => (pressed ? styles.pressed : undefined)}
            >
              {content}
            </Pressable>
          )
        })}
      </Card>

      {onPage && (lastPage ?? 1) > 1 ? (
        <View style={styles.pager}>
          <Button
            label="Previous"
            variant="ghost"
            onPress={() => onPage(Math.max(1, (page ?? 1) - 1))}
            disabled={(page ?? 1) <= 1}
            style={{ flex: 1 }}
          />
          <View style={{ alignItems: 'center', justifyContent: 'center', minWidth: 96 }}>
            <Muted>
              {page ?? 1} of {lastPage ?? 1}
            </Muted>
            {total ? <Muted>{total} in total</Muted> : null}
          </View>
          <Button
            label="Next"
            variant="ghost"
            onPress={() => onPage(Math.min(lastPage ?? 1, (page ?? 1) + 1))}
            disabled={(page ?? 1) >= (lastPage ?? 1)}
            style={{ flex: 1 }}
          />
        </View>
      ) : null}
    </>
  )
}

/** The usual row: a title, a line of detail, and a status on the right. */
export function Row({
  title,
  subtitle,
  trailing,
  status,
  statusTone,
}: {
  title: string
  subtitle?: string | null
  trailing?: string | null
  status?: string | null
  statusTone?: string
}) {
  return (
    <View style={styles.rowInner}>
      <View style={{ flex: 1, gap: 2 }}>
        <Text style={styles.rowTitle}>{title}</Text>
        {subtitle ? <Muted>{subtitle}</Muted> : null}
      </View>
      <View style={{ alignItems: 'flex-end', gap: 5 }}>
        {trailing ? <Text style={styles.rowTrailing}>{trailing}</Text> : null}
        {status ? <Pill text={status.toLowerCase()} tone={statusTone} /> : null}
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  search: {
    backgroundColor: theme.bg2,
    borderWidth: 1,
    borderColor: theme.lineStrong,
    borderRadius: 12,
    color: theme.text,
    fontSize: 16,
    paddingHorizontal: 14,
    minHeight: 52,
  },

  row: {
    paddingHorizontal: 16,
    paddingVertical: 13,
    borderBottomWidth: 1,
    borderBottomColor: theme.line,
  },
  rowLast: { borderBottomWidth: 0 },
  rowInner: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  rowTitle: { color: theme.text, fontSize: 15, fontWeight: '600' },
  rowTrailing: { color: theme.text, fontSize: 14, fontWeight: '700' },
  pressed: { backgroundColor: theme.surface2 },

  pager: { flexDirection: 'row', gap: 10, alignItems: 'center' },
})
