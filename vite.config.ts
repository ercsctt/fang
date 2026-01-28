import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import fs from 'fs';

const host = 'fang.internal';

export default defineConfig(({ command }) => {
    const keyPath = `/home/eric/.config/valet/Certificates/${host}.key`;
    const certPath = `/home/eric/.config/valet/Certificates/${host}.crt`;

    const useHttps =
        command === 'serve' &&
        fs.existsSync(keyPath) &&
        fs.existsSync(certPath);

    return {
        plugins: [
            laravel({
                input: ['resources/js/app.ts'],
                ssr: 'resources/js/ssr.ts',
                refresh: true,
            }),
            tailwindcss(),
            wayfinder({ formVariants: true }),
            vue({
                template: {
                    transformAssetUrls: { base: null, includeAbsolute: false },
                },
            }),
        ],

        server: command === 'serve'
            ? {
                host,
                hmr: { host },
                ...(useHttps
                    ? {
                        https: {
                            key: fs.readFileSync(keyPath),
                            cert: fs.readFileSync(certPath),
                        },
                    }
                    : {}),
            }
            : undefined,
    };
});
