import { File, Paths } from 'expo-file-system'
import * as Sharing from 'expo-sharing'
import { Platform } from 'react-native'
import { api } from './api'

/**
 * Open a receipt PDF.
 *
 * Deliberately **not** by putting the token in the URL. A link like
 * `?access_token=…` ends up in the web server's access log, in the browser's
 * history, and in the `Referer` header of anything the document links to — and
 * a member token is valid for six months. A credential belongs in a header.
 *
 * So the file is fetched with the Authorization header attached and then handed
 * to the platform: the share sheet on a phone, a blob URL in a browser.
 */
export async function openReceipt(paymentId: number, receiptNumber: string): Promise<void> {
  const path = `/member/receipts/${paymentId}`
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

  // The cache directory, not documents: a receipt can always be fetched again,
  // and the system is welcome to reclaim the space.
  const destination = new File(Paths.cache, `receipt-${receiptNumber}.pdf`)

  const file = await File.downloadFileAsync(`${api.defaults.baseURL}${path}`, destination, {
    headers: authorization ? { Authorization: authorization, Accept: 'application/pdf' } : {},
    // Opening the same receipt twice must not fail on the second attempt.
    idempotent: true,
  })

  if (!(await Sharing.isAvailableAsync())) {
    throw new Error('This device cannot open PDFs.')
  }

  await Sharing.shareAsync(file.uri, {
    mimeType: 'application/pdf',
    dialogTitle: `Receipt ${receiptNumber}`,
    UTI: 'com.adobe.pdf',
  })
}
