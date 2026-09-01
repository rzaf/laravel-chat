/// <reference types="vite/client" />

import type Pusher from 'pusher-js';

interface ImportMetaEnv {
    readonly VITE_REVERB_APP_KEY: string;
    readonly VITE_REVERB_HOST: string;
    readonly VITE_REVERB_PORT: string;
    readonly VITE_REVERB_SCHEME: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}

declare global {
    interface Window {
        // laravel-echo reads Pusher off the global scope.
        Pusher: typeof Pusher;
    }
}

export {};
