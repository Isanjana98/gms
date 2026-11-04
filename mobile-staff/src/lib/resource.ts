import { useMutation, useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { useState } from 'react'
import { api, errorMessage } from './api'
import type { Paginated } from './types'

/**
 * The plumbing every module screen repeats.
 *
 * Twenty modules hand-rolling their own list query, their own search debounce
 * and their own save-then-invalidate is twenty chances to get the cache key
 * subtly wrong. They all talk to the same REST shape, so they share it here
 * and each screen is left with only what makes it different.
 *
 * This is the one place the staff app diverges structurally from the admin
 * panel rather than copying it: a phone shows one list at a time and reloads
 * on focus, so the caching rules are not the panel's.
 */

/** Anything the API returns with an `id`. */
export type Resource = { id: number | string }

/**
 * A paginated list, with a search box that does not fire on every keystroke.
 *
 * `term` is what the user has typed; `search` is what has been sent. Keeping
 * them apart is what lets the input stay responsive while the network lags,
 * and stops a fast typist queuing eight requests for prefixes of one word.
 */
export function useSearchableList<T>(
  path: string,
  options: {
    perPage?: number
    params?: Record<string, string | number | boolean | undefined>
    enabled?: boolean
    minChars?: number
  } = {},
) {
  const { perPage = 20, params = {}, enabled = true, minChars = 0 } = options

  const [term, setTerm] = useState('')
  const [page, setPage] = useState(1)

  const search = term.trim()
  const ready = enabled && (search.length === 0 || search.length >= minChars)

  const query = useQuery({
    queryKey: [path, search, page, params] as QueryKey,
    enabled: ready,
    // A list the user has already seen should not blink back to a spinner
    // while the next page loads.
    placeholderData: (previous) => previous,
    queryFn: async () => {
      const { data } = await api.get<Paginated<T>>(path, {
        params: { ...params, search: search || undefined, page, per_page: perPage },
      })
      return data
    },
  })

  return {
    term,
    setTerm: (value: string) => {
      setTerm(value)
      setPage(1)
    },
    page,
    setPage,
    query,
    rows: query.data?.data ?? [],
    // The API returns pagination either nested under `meta` or flat on the
    // envelope, depending on the resource. Read both rather than picking one
    // and quietly showing "1 of 1" on half the screens.
    total: query.data?.meta?.total ?? query.data?.total ?? query.data?.data?.length ?? 0,
    lastPage: query.data?.meta?.last_page ?? query.data?.last_page ?? 1,
  }
}

/** One record. */
export function useResource<T>(path: string, id: string | number | undefined) {
  return useQuery({
    queryKey: [path, id] as QueryKey,
    enabled: id !== undefined && id !== '',
    queryFn: async () => {
      const { data } = await api.get<{ data: T } | T>(`${path}/${id}`)
      return (data as { data: T }).data ?? (data as T)
    },
  })
}

/**
 * Create or update, then invalidate everything under that path.
 *
 * Invalidating the whole path rather than one key is deliberate: saving a
 * payment changes the member's balance, the day's takings and the outstanding
 * report, and a screen showing a stale number after a save it just made is the
 * failure people actually notice.
 */
export function useSaveResource<T extends object>(
  path: string,
  options: { id?: string | number; method?: 'post' | 'put' | 'patch'; also?: string[] } = {},
) {
  const queryClient = useQueryClient()
  const { id, also = [] } = options
  const method = options.method ?? (id ? 'put' : 'post')

  return useMutation({
    mutationFn: async (payload: T) => {
      const url = id ? `${path}/${id}` : path
      const { data } = await api.request({ url, method, data: payload })
      return data
    },
    onSuccess: async () => {
      for (const key of [path, ...also]) {
        await queryClient.invalidateQueries({ queryKey: [key] })
      }
      await queryClient.invalidateQueries({ queryKey: ['summary'] })
    },
  })
}

/** Fire a one-off action, such as renewing or suspending. */
export function useAction(options: { invalidate?: string[] } = {}) {
  const queryClient = useQueryClient()
  const { invalidate = [] } = options

  return useMutation({
    mutationFn: async ({
      url,
      payload,
      method = 'post',
    }: {
      url: string
      payload?: unknown
      method?: 'post' | 'put' | 'patch' | 'delete'
    }) => {
      const { data } = await api.request({ url, method, data: payload })
      return data
    },
    onSuccess: async () => {
      for (const key of invalidate) {
        await queryClient.invalidateQueries({ queryKey: [key] })
      }
      await queryClient.invalidateQueries({ queryKey: ['summary'] })
    },
  })
}

/**
 * Turn a failed save into something a person can act on.
 *
 * Laravel returns 422 with a field-keyed bag. Showing "The given data was
 * invalid" hides the one sentence that says which field, so the bag is
 * flattened and the first real message wins.
 */
export function saveError(error: unknown): string {
  const bag = (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response
    ?.data?.errors

  if (bag) {
    const first = Object.values(bag).flat()[0]
    if (first) return first
  }

  return errorMessage(error)
}

/** Field-keyed messages, for putting an error under the field that caused it. */
export function fieldErrors(error: unknown): Record<string, string> {
  const bag = (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response
    ?.data?.errors

  if (!bag) return {}

  return Object.fromEntries(Object.entries(bag).map(([key, messages]) => [key, messages[0]]))
}
