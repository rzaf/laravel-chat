<!-- src/components/chat/MessageBubble.vue -->
<script setup lang="ts">
import { useDateFormat } from '@vueuse/core';
import type { Attachment } from '@/types';
import MessageAttachments from '@/components/chat/MessageAttachments.vue';

defineProps<{
    id?: number;
    message: string;
    time: string;
    isSent?: boolean;
    attachments?: Attachment[];
    isMenuOpen?: boolean;
}>();

const emit = defineEmits<{
    (e: 'openMenu', event: MouseEvent): void;
}>();
</script>

<template>
    <div class="z-10 flex items-center px-3 my-2 chat" :class="{ 'flex-row-reverse': isSent }">
        <div class="rounded-xl min-w-32 max-w-[90%] sm:max-w-[75%] transition-all duration-150" :class="{
            'text-gray-200 ml-6 bg-blue-700 shadow-sm': isSent,
            'mr-6 bg-white dark:bg-gray-600 shadow-sm': !isSent,
            'ring-2 ring-gray-500 dark:ring-gray-400': isMenuOpen
        }" @contextmenu.prevent="emit('openMenu', $event)">
            <MessageAttachments :attachments="attachments" :is-sent="isSent" />

            <div class="flex flex-col p-2.5">
                <p v-if="message" class="pb-1 text-base whitespace-pre-line wrap-break-word">{{ message }}</p>
                <p class="self-end ml-2 mt-1 text-xs leading-none opacity-80">
                    {{ useDateFormat(time, 'HH:mm A') }}
                </p>
            </div>
        </div>
    </div>
</template>