import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'url'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      // Esto le enseña a Vite que '@' apunta a la carpeta 'src'
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  }
})
