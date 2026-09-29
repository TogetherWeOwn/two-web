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
                // TOG-8863: was-this-helpful votes on the FAQ page. Same deal:
                // its own entry, downloaded only by /faq.
                'resources/js/faq-votes.js',
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
