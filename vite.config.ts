import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import fs from 'node:fs';
import { defineConfig } from 'vite';

// Local TLS for the dev server. The repo folder is "CPD-Dump" but the site
// runs at lowercase cpd-dump.test, so we bypass Herd auto-detection and use
// the cert copied into storage/certs (see docs/local-dev notes).
const certPath = 'storage/certs/cpd-dump.test.crt';
const keyPath = 'storage/certs/cpd-dump.test.key';
const hasLocalTls = fs.existsSync(certPath) && fs.existsSync(keyPath);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    server: hasLocalTls
        ? {
              host: '0.0.0.0',
              https: {
                  cert: fs.readFileSync(certPath),
                  key: fs.readFileSync(keyPath),
              },
              hmr: { host: 'cpd-dump.test' },
              cors: true,
          }
        : undefined,
});
