import { createRouter, createWebHistory } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import useAuthStore from '@/stores/auth';

declare module 'vue-router' {
    interface RouteMeta {
        requiresAuth?: boolean;
        guestOnly?: boolean;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        name: 'home',
        component: () => import('@/views/HomeView.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/users/:username',
        name: 'privateChats',
        component: () => import('@/views/HomeView.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/sign-in',
        name: 'login',
        component: () => import('@/views/LoginView.vue'),
        meta: { guestOnly: true },
    },
    {
        path: '/sign-up',
        name: 'register',
        component: () => import('@/views/RegisterView.vue'),
        meta: { guestOnly: true },
    },
    {
        path: '/auth-callback/:provider',
        name: 'oauthCallback',
        component: () => import('@/views/OAuthCallbackView.vue'),
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'notFound',
        component: () => import('@/views/HomeView.vue'),
        meta: { requiresAuth: true },
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach((to) => {
    const auth = useAuthStore();
    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return { name: 'login' };
    }
    if (to.meta.guestOnly && auth.isAuthenticated) {
        return { name: 'home' };
    }
    return true;
});

export default router;
