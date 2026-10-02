import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// Separate from vite.config.js on purpose: laravel-vite-plugin refuses to run when CI=true.
export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.{js,jsx}'],
    },
});
