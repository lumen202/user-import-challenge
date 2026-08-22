import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The dev server proxies API calls to the PHP server, so the UI and the API
// share an origin during development:
//   php -S localhost:8000 -t public public/index.php
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': 'http://localhost:8000',
    },
  },
});
