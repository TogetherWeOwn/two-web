import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/hallmark.css',
                'resources/js/app.js',
                // TOG-7262: copy-link on the event page. Its own entry so the
                // global bundle stays import-free (see AssetCompressionTest) and
                // only event pages download it.
                'resources/js/event-copy-link.js',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
