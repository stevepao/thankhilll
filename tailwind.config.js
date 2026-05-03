/** @type {import('tailwindcss').Config} */
module.exports = {
  prefix: 'tn-',
  corePlugins: {
    preflight: false,
  },
  content: ['./**/*.php'],
  theme: {
    extend: {
      colors: {
        tn: {
          bg: 'var(--tn-bg)',
          surface: 'var(--tn-surface)',
          ink: 'var(--tn-ink)',
          muted: 'var(--tn-muted)',
          accent: 'var(--tn-accent)',
        },
      },
      maxWidth: {
        readable: '40rem',
      },
      boxShadow: {
        tn: '0 1px 2px 0 rgb(0 0 0 / 0.045)',
      },
    },
  },
  plugins: [],
};
