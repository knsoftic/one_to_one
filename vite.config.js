import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/chat/index.js', 'resources/js/auth/qr-login.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
