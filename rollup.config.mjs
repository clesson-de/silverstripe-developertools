export default [
  {
    input: 'client/admin/src/index.js',
    output: {
      file: 'client/admin/dist/bundle.js',
      format: 'iife',
    },
  },
  {
    input: 'client/frontend/src/toasts.js',
    output: {
      file: 'client/frontend/dist/toasts.js',
      format: 'iife',
    },
  },
];
