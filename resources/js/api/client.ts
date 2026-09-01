import axios from 'axios';
import type { AxiosError, AxiosInstance, InternalAxiosRequestConfig } from 'axios';
import useAuthStore from '@/stores/auth';
import router from '@/router';

/**
 * Authenticated API client. Attaches the bearer token on every request and,
 * on a 401, clears the session and sends the user to the login page.
 *
 * The auth store is resolved lazily inside the interceptors so this module has
 * no side effects at import time (Pinia is guaranteed active by the time a
 * request actually fires).
 */
export const apiClient: AxiosInstance = axios.create();

apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
    const auth = useAuthStore();
    config.headers.Accept = 'application/json';
    if (auth.user?.token) {
        config.headers.Authorization = `Bearer ${auth.user.token}`;
    }
    return config;
});

apiClient.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        if (error.response?.status === 401) {
            useAuthStore().logout();
            if (router.currentRoute.value.name !== 'login') {
                router.push({ name: 'login' });
            }
        }
        return Promise.reject(error);
    },
);

/** Client for auth-exempt endpoints (login / register / oauth). */
export const publicClient: AxiosInstance = axios.create({
    headers: { Accept: 'application/json' },
});
