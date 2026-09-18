/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./public_html/**/*.php",
  ],
  safelist: [
    "lg:grid-cols-3",
    "lg:grid-cols-4",
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          50: "#eff6ff",
          100: "#dbeafe",
          200: "#bfdbfe",
          500: "#3b82f6",
          600: "#2563eb",
          700: "#1d4ed8",
        },
      },
    },
  },
  plugins: [],
};
