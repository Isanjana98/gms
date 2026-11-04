import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'

export default defineConfig(({ mode }) => {
  // '' as the prefix so plain names are read too: this one is consumed by the
  // dev server in Node, never shipped to the browser, so it needs no VITE_.
  const env = loadEnv(mode, process.cwd(), '')

  /*
   * Where the API is.
   *
   * Configurable because 8000 is a popular port — another application already
   * listening on it makes every `/api` call return that application's 404,
   * which looks exactly like a broken back end and is not. Set
   * API_PROXY_TARGET in web/.env to match whatever `php artisan serve` printed.
   */
  const target = env.API_PROXY_TARGET || 'http://127.0.0.1:8000'

  /*
   * Where the built panel is served from.
   *
   * On one origin with the API — which is the arrangement that needs no CORS
   * and no cookie exceptions — Laravel already owns `/` for the public
   * website and `/health/{token}` for the signed health form. So the panel
   * gets its own prefix, and every asset URL has to agree with it.
   *
   * Set ADMIN_BASE=/admin/ when building for that layout. The trailing slash
   * matters to Vite.
   */
  const base = env.ADMIN_BASE || '/'

  return {
    base,
    plugins: [react(), tailwindcss()],
    resolve: {
      alias: { '@': path.resolve(import.meta.dirname, './src') },
    },
    server: {
      port: Number(env.WEB_PORT || 5173),
      // Proxying keeps the browser on one origin in development, so CORS and
      // cookie rules behave the same here as they do behind a reverse proxy in
      // production.
      proxy: {
        '/api': { target, changeOrigin: true },
        '/storage': { target, changeOrigin: true },
      },
    },
  }
})
