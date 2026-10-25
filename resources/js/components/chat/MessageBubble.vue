<!-- src/components/chat/MessageBubble.vue -->
<script setup lang="ts">
import { computed } from 'vue';
import { useDateFormat } from '@vueuse/core';
import type { Attachment, Chat, ChatType, Message } from '@/types';
import MessageAttachments from '@/components/chat/MessageAttachments.vue';
import PaperClipIcon from '@/assets/svgs/PaperClip.svg';
import MegaphoneIcon from '@/assets/svgs/Megaphone.svg';
import UsersIcon from '@/assets/svgs/Users.svg';
import ForwardIcon from '@/assets/svgs/Forward.svg';
import Avatar from '@/components/chat/Avatar.vue';

const props = defineProps<{
    id?: number;
    message: string;
    time: string;
    chatType: ChatType;
    updatedAt?: string;
    isSent?: boolean;
    attachments?: Attachment[];
    isMenuOpen?: boolean;
    /** The message this one replies to. */
    replyTo?: Message | null;
    /** Display name of the replied-to message's author ("You" or the contact's name). */
    replyAuthor: string | null;
    /** The chat (direct, group or channel) this message was forwarded from. */
    forwardedFrom?: Chat | null;
}>();

const emit = defineEmits<{
    (e: 'openMenu', event: MouseEvent): void;
    (e: 'jumpToReply', messageId: number | string): void;
}>();

/* ---------------------------------------------------------------- reply */

const replyText = computed(() => props.replyTo?.content?.trim() ?? '');
const replyHasAttachment = computed(() => (props.replyTo?.attachments_count ?? 0) > 0);

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

/* -------------------------------------------------------------- edit check */

const isEdited = computed(() => {
    if (!props.updatedAt || !props.time) return false;
    return new Date(props.updatedAt).getTime() > new Date(props.time).getTime() + 1000;
});
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
                    <ForwardIcon class="size-3.5 shrink-0" />
                    Forwarded from
                </p>
                <p class="flex items-center gap-1.5 mt-1 font-semibold">
                    <!-- group -->
                    <UsersIcon v-if="forwardKind === 'group'" class="size-3.5 shrink-0" />
                    <!-- channel -->
                    <MegaphoneIcon v-else-if="forwardKind === 'channel'" class="size-3.5 shrink-0" />
                    <RouterLink :to="`/users/${forwardSource?.slug ?? 'unknown'}`">
                        <span class="truncate">{{ forwardName }}</span>
                    </RouterLink>
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
                    <span v-if="chatType != 'direct'" class="font-semibold truncate"
                        :class="isSent ? 'text-blue-100' : 'text-blue-600 dark:text-blue-300'">
                        {{ replyAuthor ?? 'Message' }}
                    </span>
                    <span v-if="replyText" class="break-words line-clamp-2 opacity-90">{{ replyText }}</span>
                    <span v-else-if="replyHasAttachment" class="flex items-center gap-1 italic opacity-90">
                        <PaperClipIcon class="size-3.5 shrink-0" />
                        Attachment
                    </span>
                    <span v-else class="italic opacity-70">Message unavailable</span>
                </span>
            </button>

            <MessageAttachments :attachments="attachments" :is-sent="isSent" />

            <div class="flex flex-col p-2.5">
                <p v-if="message" class="pb-1 text-base whitespace-pre-line wrap-break-word">{{ message }}</p>
                <div class="flex items-center gap-1.5 self-end ml-2 mt-1 text-xs leading-none opacity-80">
                    <span v-if="isEdited" class="italic text-[10px] opacity-75">edited</span>
                    <span>{{ useDateFormat(time, 'HH:mm A') }}</span>
                </div>
            </div>
        </div>
    </div>
</template>