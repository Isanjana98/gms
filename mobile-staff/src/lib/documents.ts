import { File, Paths } from 'expo-file-system'
import * as Sharing from 'expo-sharing'
import { Platform } from 'react-native'
import { api } from './api'

/**
 * Fetch a PDF or CSV the API generates, and hand it to the platform.
 *
 * Deliberately **not** by putting the token in the URL. A link like
 * `?access_token=…` ends up in the web server's access log, in the browser's
 * history, and in the `Referer` header of anything the document links to — and
 * a staff token can read every member's telephone number. A credential belongs
 * in a header.
 *
 * Adapted from the member app's `receipt.ts`, widened to any document: the
 * staff app also exports reports, and one downloader is easier to keep correct
 * than two that differ only in their path.
 */
export async function openDocument(
  path: string,
  filename: string,
  mimeType = 'application/pdf',
): Promise<void> {
  const authorization = api.defaults.headers.common.Authorization as string | undefined

  if (Platform.OS === 'web') {
    // Through the same client, so it carries the same header.
    const response = await api.get(path, { responseType: 'blob' })
    const blobUrl = URL.createObjectURL(response.data as Blob)

    globalThis.open?.(blobUrl, '_blank')
    // Long enough for the new tab to load it; the URL is held in memory until
    // revoked.
    setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000)

    return
  }

  // The cache directory, not documents: any of these can be fetched again, and
  // the system is welcome to reclaim the space.
  const destination = new File(Paths.cache, filename)

  const file = await File.downloadFileAsync(`${api.defaults.baseURL}${path}`, destination, {
    headers: authorization ? { Authorization: authorization, Accept: mimeType } : {},
    // Opening the same document twice must not fail on the second attempt.
    idempotent: true,
  })

  if (!(await Sharing.isAvailableAsync())) {
    throw new Error('This device cannot open that file.')
  }

  await Sharing.shareAsync(file.uri, {
    mimeType,
    dialogTitle: filename,
    UTI: mimeType === 'application/pdf' ? 'com.adobe.pdf' : 'public.comma-separated-values-text',
  })
}
