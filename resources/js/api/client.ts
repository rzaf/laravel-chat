import axios from 'axios';
import type { AxiosError, AxiosInstance, InternalAxiosRequestConfig } from 'axios';
import useAuthStore from '@/stores/auth';
import router from '@/router';
import { getSocketId } from '@/realtime/echo'; // Import socket getter

export const apiClient: AxiosInstance = axios.create();

apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
    const auth = useAuthStore();
    config.headers.Accept = 'application/json';
    
    if (auth.user?.token) {
        config.headers.Authorization = `Bearer ${auth.user.token}`;
    }

    // Attach the WebSocket socket ID if connected so ->toOthers() works
    const socketId = getSocketId();
    if (socketId) {
        config.headers['X-Socket-ID'] = socketId;
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
