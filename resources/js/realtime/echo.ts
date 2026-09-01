import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { broadcastAuthorize } from '@/api';

// laravel-echo's reverb/pusher connector reads Pusher off the global scope.
window.Pusher = Pusher;

function createEcho() {
    return new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT) || 80,
        wssPort: Number(import.meta.env.VITE_REVERB_PORT) || 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authorizer: (channel: { name: string }) => ({
            authorize: (
                socketId: string,
                callback: (error: Error | false, data?: unknown) => void,
            ) => {
                broadcastAuthorize(socketId, channel.name)
                    .then((response) => callback(false, response))
                    .catch((err: Error) => callback(err));
            },
        }),
    });
}

let echo: ReturnType<typeof createEcho> | null = null;

/** Lazily create (once) and return the shared Echo instance. */
export function getEcho() {
    if (!echo) {
        echo = createEcho();
    }
    return echo;
}

/** Tear down the connection (e.g. on logout). */
export function disconnectEcho(): void {
    echo?.disconnect();
    echo = null;
}
