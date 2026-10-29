<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import { joinChatByInvite } from '@/api';
import useChatStore from '@/stores/chat';

/**
 * Landing page of an invite link: `/join/:code`.
 * Joins the group / channel, then replaces itself with `/chats/:slug`.
 */
const route = useRoute();
const router = useRouter();
const chatStore = useChatStore();

const error = ref('');

onMounted(async () => {
    const code = String(route.params.code ?? '');
    try {
        const chat = await joinChatByInvite(code);
        const stored = chatStore.upsertChat(chat);
        await router.replace(`/chats/${stored.slug}`);
    } catch (err) {
        error.value =
            isAxiosError(err) && err.response?.status === 404
                ? 'This invite link is invalid or has expired.'
                : 'Could not join. Please try again.';
        console.error('Failed to join by invite', err);
    }
});
</script>

<template>
    <div class="flex flex-col items-center justify-center flex-1 h-full gap-3 px-6 text-center">
        <template v-if="!error">
            <div class="flex items-center justify-center space-x-2" role="status" aria-label="Joining">
                <span style="animation-delay: -300ms" class="bg-gray-400 rounded-full size-2.5 animate-bounce"></span>
                <span style="animation-delay: -200ms" class="bg-gray-400 rounded-full size-2.5 animate-bounce"></span>
                <span style="animation-delay: -100ms" class="bg-gray-400 rounded-full size-2.5 animate-bounce"></span>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400">Joining…</p>
        </template>
        <template v-else>
            <h2 class="text-lg font-medium text-gray-800 dark:text-gray-100">{{ error }}</h2>
            <RouterLink to="/" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline">
                Back to chats
            </RouterLink>
        </template>
    </div>
</template>