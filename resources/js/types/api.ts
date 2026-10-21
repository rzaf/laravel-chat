/** Transport-layer shapes: request/response envelopes returned by the API. */

import type { User } from '@/types';

/** Response of `POST /api/login` and `POST /api/register`. */
export interface AuthResponse {
    token: string;
    data: User;
    message?: string;
}

/** Laravel `simplePaginate()` envelope. */
export interface Paginated<T> {
    current_page: number;
    data: T[];
    first_page_url: string;
    next_page_url: string | null;
    prev_page_url: string | null;
    path: string;
    per_page: number;
    from: number | null;
    to: number | null;
}

export interface ApiResponse<T> {
    data: any;
    message?: string;
}

export interface ResourceCollectionPaginated<T> {
    data: T[];
    links: {
        first: string;
        last: string;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        links?: {
            url: string | null;
            label: string;
            page?: number | null;
            active: boolean;
        }[];
        path: string;
        per_page: number;
        to: number | null;
        total?: number;
    };
}

/** Shape returned by Reverb/Pusher for `POST /api/broadcasting/auth`. */
export interface BroadcastAuthResponse {
    auth: string;
    channel_data?: string;
}

/** Laravel validation error body (HTTP 422) or generic message (4xx). */
export interface ApiErrorBody {
    message?: string;
    errors?: Record<string, string[]>;
}

/** Response of the OAuth redirect endpoint. */
export interface OAuthRedirectResponse {
    url: string;
}

export interface LoginCredentials {
    login: string;
    password: string;
}

export interface RegisterPayload {
    name: string;
    username: string;
    email: string;
    phone: string | null;
    password: string;
    password_confirmation: string;
}

export interface DeleteChatPayload {
    delete_for_everyone?: boolean;
}

export interface DeleteMessagePayload {
    delete_for_everyone?: boolean;
}

export interface ClearChatHistoryPayload {
    delete_for_everyone?: boolean;
}