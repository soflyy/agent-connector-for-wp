import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// React comes from WordPress core, not the bundle: these imports map to the
// globals of core's `react`, `react-dom` and `react-jsx-runtime` script
// handles (enqueued as dependencies in src/Admin/ConnectionPage.php). Sharing
// core's React also lets other plugins render into the app's extension points.
const WP_GLOBALS = {
  react: 'React',
  'react-dom': 'ReactDOM',
  'react-dom/client': 'ReactDOM',
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
        // A classic script wrapped in an IIFE, so minified variable names
        // (e.g. `wp`) don't collide with WordPress's own global `var wp = {}`.
        format: 'iife',
        globals: WP_GLOBALS,
        entryFileNames: 'assets/index.js',
        chunkFileNames: 'assets/[name].js',
        assetFileNames: (info) => info.name?.endsWith('.css') ? 'assets/index.css' : 'assets/[name].[ext]',
      },
    },
    // One CSS file next to the bundle (an IIFE build would otherwise inject
    // the CSS from JS at runtime).
    cssCodeSplit: false,
    // No code splitting — single bundle for simplicity.
    chunkSizeWarningLimit: 1000,
  },
})
