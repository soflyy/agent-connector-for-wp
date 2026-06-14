/** @type {import('tailwindcss').Config} */
export default {
  // Scope all utilities to our app wrapper so WP admin styles aren't clobbered.
  important: '#agent-connector-for-wp-app',
  content: ['./src/**/*.{js,jsx}'],
  theme: {
    extend: {
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
