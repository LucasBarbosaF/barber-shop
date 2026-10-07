import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/admin.css',
                'resources/js/admin.js',
                'resources/js/booking.js',
                'resources/css/login.css',
                'resources/css/booking.css',
                'resources/css/agenda.css',
                'resources/js/agenda.js'
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
