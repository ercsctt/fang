import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import fs from 'fs';

const host = 'fang.internal';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        host,
        hmr: { host },
        https: {
            key: fs.readFileSync(`/home/eric/.config/valet/Certificates/${host}.key`),
            cert: fs.readFileSync(`/home/eric/.config/valet/Certificates/${host}.crt`),
        },
    },
});
