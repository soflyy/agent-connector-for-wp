import type { Config } from "tailwindcss";

const config: Config = {
  content: [
    "./pages/**/*.{js,ts,jsx,tsx,mdx}",
    "./components/**/*.{js,ts,jsx,tsx,mdx}",
    "./app/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      colors: {
        wp: {
          blue: "#0073aa",
          "blue-dark": "#005a87",
          "blue-light": "#00a0d2",
        },
      },
    },
  },
  plugins: [],
};

export default config;
