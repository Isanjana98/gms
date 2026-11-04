import axios, { type AxiosError } from 'axios'

/**
 * The session lives in an httpOnly cookie set by the server.
 *
 * There is deliberately no token handling in here any more. The token used to
 * be kept in localStorage, where any script on the page could read it — so a
 * single cross-site scripting hole anywhere in this front end would have handed
 * an attacker a working session they could keep using from their own machine.
 * The cookie is not readable by script at all.
 *
 * The cost of that is that the browser now attaches the credential to requests
 * on its own, which is what CSRF abuses. Three things answer it: the cookie is
 * SameSite=Lax, so the browser withholds it from cross-site writes; the server
 * requires the custom header set below on cookie-authenticated writes, which a
 * cross-site form cannot send; and CORS names the only origins allowed to send
 * credentials at all.
 */
export const api = axios.create({
  baseURL: '/api',
  headers: {
    Accept: 'application/json',
    // Proof to the server that this came from our own front end. A cross-site
    // form cannot set a custom header, and a cross-site fetch that tries must
    // first pass a CORS preflight the API refuses.
    'X-GMS-Request': '1',
  },
  // Send the session cookie. Required when the API is on another origin, and
  // harmless when it is proxied onto this one.
  withCredentials: true,
})

/**
 * A 401 means the session is gone, expired, or was ended for inactivity — so
 * send the user to sign in. Done here rather than in each screen: otherwise
 * every query would need to handle the same case, and one that forgot would
 * leave the user staring at an empty page with no explanation.
 *
 * 419 is the CSRF refusal. It means the same thing to the user — start again —
 * and is handled identically rather than surfacing a message about headers
 * that nobody at a reception desk can act on.
 */
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    const status = error.response?.status
    const url = error.config?.url ?? ''

    /*
     * The session probe and the sign-in attempt are exempt.
     *
     * Every page load asks /auth/me whether there is a session, and for
     * somebody who is simply not signed in the answer is 401 — an ordinary
     * answer, not an expiry. Redirecting on it would greet every first-time
     * visitor with "your session has expired", and a failed sign-in would
     * reload the page out from under the error message explaining why.
     */
    const isSessionProbe = url.startsWith('/auth/me') || url.startsWith('/auth/login')

    if ((status === 401 || status === 419) && !isSessionProbe && !window.location.pathname.startsWith('/login')) {
      // The server clears the cookie; the reload is what discards the
      // in-memory user so no stale screen is left behind it.
      window.location.assign('/login?expired=1')
    }

    return Promise.reject(error)
  },
)

type LaravelError = {
  message?: string
  errors?: Record<string, string[]>
}

/**
 * Turn any failure into one sentence worth showing someone.
 *
 * Laravel returns validation detail under `errors`, a plain `message` for
 * business-rule refusals, and nothing at all when the server is unreachable.
 * Reception should see the specific reason in every case, not "Request
 * failed with status code 422".
 */
export function errorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  const axiosError = error as AxiosError<LaravelError>

  if (axiosError?.response) {
    const data = axiosError.response.data

    const firstValidationError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    if (firstValidationError) return firstValidationError

    if (data?.message) return data.message

    if (axiosError.response.status === 403) return 'You do not have permission to do that.'
    if (axiosError.response.status === 404) return 'Not found.'
  }

  if (axiosError?.request) {
    return 'Cannot reach the server. Check that the API is running.'
  }

  return fallback
}

/**
 * Open or save a file from an authenticated endpoint.
 *
 * Now that the session is a cookie, a plain `<a href="/api/...">` would in
 * fact authenticate — but it is still not used, for two reasons that have
 * nothing to do with auth. A link cannot report a failure: when the server
 * refuses, the browser replaces the tab with raw JSON, which is how the
 * receipts bug presented the first time. And a link cannot show that anything
 * is happening while a large export is being generated.
 *
 * So the file is fetched through the same client as everything else, and the
 * resulting blob is handed to the browser.
 *
 * The empty tab is opened *before* the await, synchronously inside the click,
 * because a popup opened after an await is blocked. If the browser blocks it
 * anyway, the file is saved instead of shown.
 */
export async function openFile(url: string, filename: string, mode: 'view' | 'download' = 'view'): Promise<void> {
  const tab = mode === 'view' ? window.open('', '_blank') : null

  if (tab) {
    tab.document.write('<title>Loading…</title><p style="font:14px system-ui;padding:2rem">Preparing document…</p>')
  }

  try {
    const response = await api.get(url, { responseType: 'blob' })
    const blobUrl = URL.createObjectURL(response.data as Blob)

    if (tab && !tab.closed) {
      tab.location.replace(blobUrl)
    } else {
      const link = document.createElement('a')
      link.href = blobUrl
      link.download = filename
      document.body.appendChild(link)
      link.click()
      link.remove()
    }

    // Long enough for the tab to load the blob; the URL is held in memory
    // until revoked, and a reception desk opens a lot of receipts in a shift.
    setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000)
  } catch (error) {
    tab?.close()

    // With responseType 'blob' a JSON error body also arrives as a Blob, so
    // errorMessage() would report "something went wrong" and throw away the
    // server's actual explanation. Unwrap it first.
    const data = (error as AxiosError)?.response?.data

    if (data instanceof Blob) {
      let message = 'Could not open the file.'

      try {
        const parsed = JSON.parse(await data.text())
        if (parsed?.message) message = parsed.message
      } catch {
        // Not JSON — the generic message stands.
      }

      throw new Error(message)
    }

    throw error
  }
}

/** Field-level validation errors, for marking up the offending inputs. */
export function fieldErrors(error: unknown): Record<string, string> {
  const errors = (error as AxiosError<LaravelError>)?.response?.data?.errors
  if (!errors) return {}

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}
