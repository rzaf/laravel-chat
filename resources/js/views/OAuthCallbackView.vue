<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { oauthCallback } from '@/api';
import useAuthStore from '@/stores/auth';

const auth = useAuthStore();
const loading = ref(true);
const error = ref(false);
const router = useRouter();
const route = useRoute();

onMounted(async () => {
    const code = route.query.code as string | undefined;
    const provider = route.params.provider as string;

    if (code && provider) {
        await handleOAuthCallback(provider, code);
    } else {
        error.value = true;
        loading.value = false;
        setTimeout(() => router.replace({ name: 'login' }), 3000);
    }
});

async function handleOAuthCallback(provider: string, code: string): Promise<void> {
    try {
        const res = await oauthCallback(provider, code);
        auth.setUser(res);
        router.push('/');
    } catch {
        error.value = true;
        setTimeout(() => router.replace({ name: 'login' }), 3000);
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="min-h-screen flex flex-col justify-center items-center text-center px-4">
        <div v-if="loading" class="text-lg font-medium animate-pulse text-gray-600">
            Logging you in...
        </div>
        <div v-else-if="error" class="text-red-600">
            <p class="text-lg font-semibold">Login failed.</p>
            <p class="text-sm">Redirecting back to login...</p>
        </div>
        <div v-else>
            <p class="text-green-600">Logged in successfully! Redirecting...</p>
        </div>
    </div>
</template>
