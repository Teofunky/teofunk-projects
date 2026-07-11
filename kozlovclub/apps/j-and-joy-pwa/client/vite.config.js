import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      'libsodium-wrappers-sumo': path.resolve(__dirname, '../node_modules/libsodium-wrappers-sumo/dist/modules-sumo/libsodium-wrappers.js'),
    },
  },
  optimizeDeps: {
    include: ['libsodium-wrappers-sumo'],
  },
  server: {
    host: true,
    port: 5173,
    proxy: {
      '/api': 'http://localhost:8080',
      '/socket.io': { target: 'http://localhost:8080', ws: true },
    },
  },
});
