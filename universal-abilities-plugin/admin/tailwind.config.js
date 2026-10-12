/** @type {import('tailwindcss').Config} */
export default {
  // Rendered inside Agent Connector's app, so scope to its wrapper the same way
  // its own Tailwind build does. Its stylesheet already ships Tailwind's base
  // layer, so this one only adds the utilities our components use.
  important: '#agent-connector-for-wp-app',
  content: ['./src/**/*.{js,jsx}'],
  corePlugins: { preflight: false },
  theme: {
    extend: {
      // Same namespaced spinner as Agent Connector (see its tailwind.config.js).
      keyframes: {
        'acfw-spin': {
          from: { transform: 'rotate(0deg)' },
          to: { transform: 'rotate(360deg)' },
        },
      },
      animation: {
        spin: 'acfw-spin 1s linear infinite',
      },
    },
  },
  plugins: [],
}
