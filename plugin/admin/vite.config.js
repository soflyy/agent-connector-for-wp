import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'build',
    rollupOptions: {
      input: 'src/main.jsx',
      output: {
        entryFileNames: 'assets/index.js',
        chunkFileNames: 'assets/[name].js',
        assetFileNames: (info) => info.name?.endsWith('.css') ? 'assets/index.css' : 'assets/[name].[ext]',
        // Wrap in IIFE so minified variable names (e.g. `wp`) don't collide
        // with WordPress's own global `var wp = {}` that ships on every admin page.
        banner: '(function(){',
        footer: '})();',
      },
    },
    // No code splitting — single bundle for simplicity.
    chunkSizeWarningLimit: 1000,
  },
})
