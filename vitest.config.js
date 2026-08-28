import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';

// Frontend unit tests (npm test). Kept apart from vite.config.js because the
// laravel-vite-plugin expects a real app build; tests only need the react
// plugin and the same '@' alias the plugin provides at build time.
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['resources/js/tests/setup.js'],
        include: ['resources/js/tests/**/*.test.jsx'],
    },
});
