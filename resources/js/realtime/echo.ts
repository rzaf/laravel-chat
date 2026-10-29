import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { broadcastAuthorize } from '@/api';

window.Pusher = Pusher;
let currentSocketId: string | null = null;

function createEcho() {
    const echoInstance = new Echo({
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

    // Capture socket ID as soon as it connects
    echoInstance.connector.pusher.connection.bind('connected', () => {
        currentSocketId = echoInstance.socketId() ?? null;
    });

    return echoInstance;
}

let echo: ReturnType<typeof createEcho> | null = null;

export function getEcho() {
    if (!echo) {
        echo = createEcho();
    }
    return echo;
}

export function disconnectEcho(): void {
    setSocketId(null);
    echo?.disconnect();
    echo = null;
}

export function getSocketId(): string | null {
    return currentSocketId;
}