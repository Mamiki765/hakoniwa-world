import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/manual.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    test: {
        environment: 'jsdom',
        projects: [
            {
                extends: true,
                test: {
                    name: 'shared',
                    include: ['resources/js/**/*.shared.test.ts'],
                },
            },
            {
                extends: true,
                test: {
                    name: 'surface',
                    include: ['resources/js/**/*.surface.test.ts'],
                },
            },
            {
                extends: true,
                test: {
                    name: 'underground',
                    include: ['resources/js/**/*.underground.test.ts'],
                },
            },
        ],
    },
});
