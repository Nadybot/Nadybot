import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import { compression } from 'vite-plugin-compression2'


// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vuetify(),
    compression({ deleteOriginalAssets: true, exclude: /\.html$/, filename: '[path][base]' })
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },
})
