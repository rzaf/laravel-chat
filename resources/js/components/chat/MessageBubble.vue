<!-- src/components/chat/MessageBubble.vue -->
<script setup lang="ts">
import { computed } from 'vue';
import { useDateFormat } from '@vueuse/core';
import type { Attachment, Chat, Message } from '@/types';
import MessageAttachments from '@/components/chat/MessageAttachments.vue';
import Avatar from '@/components/chat/Avatar.vue';

const props = defineProps<{
    id?: number;
    message: string;
    time: string;
    isSent?: boolean;
    attachments?: Attachment[];
    isMenuOpen?: boolean;
    /** The message this one replies to. */
    replyTo?: Message | null;
    /** Display name of the replied-to message's author ("You" or the contact's name). */
    replyAuthor?: string;
    /** The chat (direct, group or channel) this message was forwarded from. */
    forwardedFrom?: Chat | null;
}>();

const emit = defineEmits<{
    (e: 'openMenu', event: MouseEvent): void;
    (e: 'jumpToReply', messageId: number | string): void;
}>();

/* ---------------------------------------------------------------- reply */

const replyText = computed(() => props.replyTo?.content?.trim() ?? '');
const replyHasAttachment = computed(() => (props.replyTo?.attachments?.length ?? 0) > 0);

function jumpToReply(): void {
    if (props.replyTo?.id != null) {
        emit('jumpToReply', props.replyTo.id);
    }
}

/* -------------------------------------------------------------- forward */

type ForwardSource = Chat & { type?: 'direct' | 'group' | 'channel' | string };

const forwardSource = computed(() => props.forwardedFrom as ForwardSource | null | undefined);
const forwardName = computed(() => forwardSource.value?.name ?? forwardSource.value?.slug ?? 'Unknown');
const forwardKind = computed(() => forwardSource.value?.type);
</script>

<template>
    <div class="z-10 flex items-center px-3 my-2 chat" :class="{ 'flex-row-reverse': isSent }">
        <div class="rounded-xl min-w-32 max-w-[90%] sm:max-w-[75%] overflow-hidden transition-all duration-150" :class="{
            'text-gray-200 ml-6 bg-blue-700 shadow-sm': isSent,
            'mr-6 bg-white dark:bg-gray-600 shadow-sm': !isSent,
            'ring-2 ring-gray-500 dark:ring-gray-400': isMenuOpen
        }" @contextmenu.prevent="emit('openMenu', $event)">

            <!-- Forwarded header -->
            <div v-if="forwardedFrom" class="px-3 pt-2.5 text-xs"
                :class="isSent ? 'text-blue-100' : 'text-blue-600 dark:text-blue-300'">
                <p class="flex items-center gap-1 italic opacity-80">
                    <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 14l5-5-5-5" />
                        <path d="M4 20v-7a4 4 0 0 1 4-4h12" />
                    </svg>
                    Forwarded from
                </p>
                <p class="flex items-center gap-1.5 mt-1 font-semibold">
                    <Avatar class="size-5 shrink-0"
                        :src="forwardedFrom.profile_photo || '/storage/default-profile.png'" />
                    <!-- group -->
                    <svg v-if="forwardKind === 'group'" class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-label="Group">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2" />
                        <circle cx="10" cy="7" r="4" />
                        <path d="M21 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                    <!-- channel -->
                    <svg v-else-if="forwardKind === 'channel'" class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-label="Channel">
                        <path d="M3 11l18-8v18L3 13z" />
                        <path d="M11.6 16.8a3 3 0 1 1-5.8-1.6" />
                    </svg>
                    <span class="truncate">{{ forwardName }}</span>
                </p>
                <div class="mt-2 border-t"
                    :class="isSent ? 'border-white/15' : 'border-black/10 dark:border-white/10'" />
            </div>

            <!-- Reply quote -->
            <button v-if="replyTo" type="button" :aria-label="`Go to replied message from ${replyAuthor ?? 'user'}`"
                class="flex w-[calc(100%-1rem)] mx-2 mt-2 overflow-hidden text-left transition-colors rounded-lg cursor-pointer"
                :class="isSent ? 'bg-white/15 hover:bg-white/20' : 'bg-blue-50 hover:bg-blue-100 dark:bg-black/20 dark:hover:bg-black/30'"
                @click.stop="jumpToReply">
                <span class="w-1 shrink-0" :class="isSent ? 'bg-blue-200' : 'bg-blue-500 dark:bg-blue-400'" />
                <span class="flex flex-col min-w-0 px-2 py-1.5 text-xs">
                    <span class="font-semibold truncate"
                        :class="isSent ? 'text-blue-100' : 'text-blue-600 dark:text-blue-300'">
                        {{ replyAuthor ?? 'Message' }}
                    </span>
                    <span v-if="replyText" class="break-words line-clamp-2 opacity-90">{{ replyText }}</span>
                    <span v-else-if="replyHasAttachment" class="flex items-center gap-1 italic opacity-90">
                        <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path
                                d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                        </svg>
                        Attachment
                    </span>
                    <span v-else class="italic opacity-70">Message unavailable</span>
                </span>
            </button>

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