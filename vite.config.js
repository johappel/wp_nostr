import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
  root: path.resolve(__dirname),
  base: './',
  build: {
    outDir: 'assets/js',
    rollupOptions: {
      input: path.resolve(__dirname, 'assets/js/spa-nostr-app.js')
    }
  }
});
