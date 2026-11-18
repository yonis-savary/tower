import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'
import { defineConfig, loadEnv } from 'vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const projectEnv = loadEnv(mode, fileURLToPath(new URL('..', import.meta.url)), 'TOWER_')

  return {
    plugins: [vue()],
    build: {
      // Browsers with light-dark() : below them, the build rewrites it to follow prefers-color-scheme,
      // which ignores the theme chosen in Tower (light theme on a dark system : dark backgrounds)
      cssTarget: ['chrome123', 'edge123', 'firefox120', 'safari17.5'],
    },
    server: {
      // The websocket server only accepts this origin : a silent fallback port would be refused
      port: Number(projectEnv.TOWER_VITE_DEV_PORT ?? 5173),
      strictPort: true,
      // Same origin as the frontend : the session cookie needs no CORS setup
      proxy: {
        '/api': `http://localhost:${projectEnv.TOWER_HTTP_PORT ?? 8081}`,
        '/mcp': `http://localhost:${projectEnv.TOWER_HTTP_PORT ?? 8081}`,
      },
    },
  }
})
