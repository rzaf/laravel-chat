<script setup lang="ts">
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import useAuthStore from '@/stores/auth';
import { disconnectEcho } from '@/realtime/echo';

const auth = useAuthStore();
const router = useRouter();

onMounted(() => {
    disconnectEcho();
    auth.logout();
    router.push('/');
});
</script>

<template>
    <div class="flex items-center justify-center w-full h-full">
        <p class="text-gray-500 animate-pulse">Signing out...</p>
    </div>
</template>
