import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// React comes from WordPress core — the same instance Agent Connector's app
// uses, which is required for our components to render inside it.
const WP_GLOBALS = {
  react: 'React',
  'react/jsx-runtime': 'ReactJSXRuntime',
}

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'build',
    rollupOptions: {
      input: 'src/main.jsx',
      external: Object.keys(WP_GLOBALS),
      output: {
        format: 'iife',
        globals: WP_GLOBALS,
        entryFileNames: 'packs.js',
        assetFileNames: (info) => info.name?.endsWith('.css') ? 'packs.css' : '[name].[ext]',
      },
    },
    cssCodeSplit: false,
  },
})
