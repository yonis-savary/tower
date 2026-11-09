import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: {
    // Same origin as the frontend : the session cookie needs no CORS setup
    proxy: { '/api': 'http://localhost:8081' },
  },
})
