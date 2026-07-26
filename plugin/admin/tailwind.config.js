/** @type {import('tailwindcss').Config} */
export default {
  // Scope all utilities to our app wrapper so WP admin styles aren't clobbered.
  important: '#agent-connector-for-wp-app',
  content: ['./src/**/*.{js,jsx}'],
  theme: {
    extend: {
      // `spin` is a global keyframes name, and wp-admin loads plenty of CSS we
      // don't control. If anything else on the page defines @keyframes spin,
      // the last definition wins and our icons animate to someone else's
      // transform, which is how a spinning icon ends up drifting off centre.
      // A namespaced animation can't be hijacked that way.
      keyframes: {
        'acfw-spin': {
          from: { transform: 'rotate(0deg)' },
          to: { transform: 'rotate(360deg)' },
        },
      },
      // Repoints the stock `animate-spin` utility at the namespaced keyframes,
      // so every existing spinner is covered without touching the components.
      animation: {
        spin: 'acfw-spin 1s linear infinite',
      },
      fontFamily: {
        sans: [
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'Roboto',
          'Oxygen-Sans',
          'Ubuntu',
          'Cantarell',
          '"Helvetica Neue"',
          'sans-serif',
        ],
      },
    },
  },
  plugins: [],
}
