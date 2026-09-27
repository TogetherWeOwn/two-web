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
                // TOG-6926: copy-link on member profiles. Its own entry so the
                // global bundle stays import-free (see AssetCompressionTest) and
                // only profile pages download it.
                'resources/js/profile-copy-link.js',
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
