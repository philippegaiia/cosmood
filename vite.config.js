import { defineConfig, loadEnv } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    let appHost = 'localhost';
    let appProtocol = 'http';

    try {
        const appUrl = new URL(env.APP_URL || 'http://localhost');
        appHost = appUrl.hostname;
        appProtocol = appUrl.protocol.replace(':', '');
    } catch {
        // Keep safe local defaults when APP_URL is malformed.
    }

    const hmrProtocol = appProtocol === 'https' ? 'wss' : 'ws';
    const devOrigin = `${appProtocol}://${appHost}:5173`;

    return {
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            origin: devOrigin,
            cors: true,
            hmr: {
                host: appHost,
                protocol: hmrProtocol,
                port: 5173,
            },
        },
        plugins: [
            tailwindcss(),
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
                refresh: true,
            }),
        ],
    };
});
