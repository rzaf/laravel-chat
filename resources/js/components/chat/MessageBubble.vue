<!-- src/components/chat/MessageBubble.vue -->
<script setup lang="ts">
import { computed } from 'vue';
import { useDateFormat } from '@vueuse/core';
import type { Attachment, Chat, ChatType, Message, MessageType } from '@/types';
import MessageAttachments from '@/components/chat/MessageAttachments.vue';
import PaperClipIcon from '@/assets/svgs/PaperClip.svg';
import MegaphoneIcon from '@/assets/svgs/Megaphone.svg';
import UsersIcon from '@/assets/svgs/Users.svg';
import ForwardIcon from '@/assets/svgs/Forward.svg';
import Avatar from '@/components/chat/Avatar.vue';

const props = defineProps<{
    id?: number;
    message: string;
    type: MessageType;
    time: string;
    chatType: ChatType;
    senderName?: string;
    senderProfile?: string;
    senderSlug?: string;
    senderId?: number;
    updatedAt?: string;
    isSent?: boolean;
    isFirst?: boolean;
    isConsecutive?: boolean;
    attachments?: Attachment[];
    isMenuOpen?: boolean;
    replyToMessageId?: number;
    replyTo?: Message | null;
    replyAuthor: string | null;
    forwardedFrom?: Chat | null;
    reactions?: {
        my_reaction: string | null;
        counts: Record<string, number>;
    };
}>();

const emit = defineEmits<{
    (e: 'openMenu', event: MouseEvent): void;
    (e: 'jumpToReply', messageId: number | string): void;
    (e: 'react', reaction: string): void;
}>();

const isFirstOfRun = computed(() => props.isFirst !== false);

const SENDER_COLORS = [
    'text-red-500 dark:text-red-400',
    'text-orange-500 dark:text-orange-400',
    'text-amber-600 dark:text-amber-400',
    'text-emerald-600 dark:text-emerald-400',
    'text-teal-600 dark:text-teal-400',
    'text-cyan-600 dark:text-cyan-400',
    'text-blue-500 dark:text-blue-400',
    'text-violet-500 dark:text-violet-400',
    'text-pink-500 dark:text-pink-400',
];

const showSender = computed(
    () => props.chatType === 'group' && !props.isSent && !!props.senderName && isFirstOfRun.value,
);

const senderColor = computed(() => {
    const key = String(props.senderId ?? props.senderName ?? '');
    let hash = 0;
    for (let i = 0; i < key.length; i++) {
        hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
    }
    return SENDER_COLORS[hash % SENDER_COLORS.length];
});

const shapeClasses = computed(() => {
    const sideTop = isFirstOfRun.value ? '' : props.isSent ? 'rounded-tr-md' : 'rounded-tl-md';
    const sideBottom = props.isSent ? 'rounded-br-md' : 'rounded-bl-md';
    return [sideTop, sideBottom];
});

const replyText = computed(() => props.replyTo?.content?.trim() ?? '');
const replyHasAttachment = computed(() => (props.replyTo?.attachments_count ?? 0) > 0);

function jumpToReply(): void {
    if (props.replyTo?.id != null) {
        emit('jumpToReply', props.replyTo.id);
    }
}

type ForwardSource = Chat & { type?: 'direct' | 'group' | 'channel' | string };
const forwardSource = computed(() => props.forwardedFrom as ForwardSource | null | undefined);
const forwardName = computed(() => forwardSource.value?.name ?? forwardSource.value?.slug ?? 'Unknown');
const forwardKind = computed(() => forwardSource.value?.type);

const isEdited = computed(() => {
    if (!props.updatedAt || !props.time) return false;
    return new Date(props.updatedAt).getTime() > new Date(props.time).getTime() + 1000;
});

const formattedTime = useDateFormat(() => props.time, 'HH:mm');
const reactionEntries = computed(() =>
    Object.entries(props.reactions?.counts ?? {}).filter(([, count]) => count > 0),
);
</script>

<template>
    <div class="chat group relative z-10 flex items-end px-1 sm:px-2 mb-2"
        :class="isSent ? 'justify-end' : 'justify-start'">

        <!-- Bubble Container -->
        <div class="relative min-w-[5.5rem] max-w-[calc(100%-3.5rem)] sm:max-w-[68%] rounded-[1.25rem] px-3.5 py-2 transition-shadow duration-200"
            :class="[
                shapeClasses,
                isSent
                    ? 'bg-linear-to-br from-blue-500 via-blue-600 to-indigo-600 text-white shadow-md shadow-blue-600/20 selection:bg-white/30'
                    : 'bg-white text-gray-900 shadow-sm shadow-gray-900/5 ring-1 ring-gray-900/5 dark:bg-gray-800 dark:text-gray-100 dark:ring-white/10',
                isMenuOpen ? 'ring-2 ring-offset-2 ring-blue-400 ring-offset-gray-50 dark:ring-blue-500 dark:ring-offset-gray-900' : '',
            ]" @contextmenu.prevent="emit('openMenu', $event)">

            <!-- Group sender name -->
            <div v-if="showSender" class="mb-0.5 text-[13px]">
                <RouterLink :to="`/chats/${senderSlug ?? 'unknown'}`" class="hover:underline">
                    <span class="font-semibold tracking-tight truncate" :class="senderColor">
                        {{ senderName }}
                    </span>
                </RouterLink>
            </div>

            <!-- Forwarded header -->
            <div v-if="forwardedFrom" class="mb-2 text-xs">
                <p class="flex items-center gap-1 text-[11px] font-medium opacity-75">
                    <ForwardIcon class="size-3 shrink-0" />
                    Forwarded from
                </p>
                <p class="flex items-center gap-1.5 mt-1 font-semibold">
                    <Avatar class="size-4 shrink-0"
                        :src="forwardedFrom.profile_photo || '/storage/default-profile.png'" />
                    <UsersIcon v-if="forwardKind === 'group'" class="size-3 shrink-0" />
                    <MegaphoneIcon v-else-if="forwardKind === 'channel'" class="size-3 shrink-0" />
                    <RouterLink v-if="forwardSource?.slug" :to="`/chats/${forwardSource?.slug ?? 'unknown'}`"
                        class="truncate hover:underline">
                        <span>{{ forwardName }}</span>
                    </RouterLink>
                    <span v-else class="truncate">{{ forwardName }}</span>
                </p>
            </div>

            <!-- Reply quote -->
            <button v-if="replyToMessageId" type="button"
                :aria-label="`Go to replied message from ${replyAuthor ?? 'user'}`"
                class="relative flex w-full mb-2 overflow-hidden text-left transition-colors cursor-pointer rounded-xl"
                :class="isSent
                    ? 'bg-white/15 hover:bg-white/25'
                    : 'bg-blue-50 hover:bg-blue-100/80 dark:bg-gray-900/60 dark:hover:bg-gray-900'"
                @click.stop="jumpToReply">
                <span class="w-1 shrink-0" :class="isSent ? 'bg-white/80' : 'bg-blue-500'" />
                <span class="flex flex-col min-w-0 px-2.5 py-1.5 text-xs">
                    <span v-if="chatType !== 'direct'" class="font-semibold truncate"
                        :class="isSent ? 'text-white' : 'text-blue-600 dark:text-blue-400'">
                        {{ replyAuthor ?? 'Message' }}
                    </span>
                    <span v-if="replyTo === null" class="italic opacity-70">Message unavailable</span>
                    <span v-else-if="replyText" class="break-words line-clamp-2 opacity-85">{{ replyText }}</span>
                    <span v-else-if="replyHasAttachment" class="flex items-center gap-1 italic opacity-85">
                        <PaperClipIcon class="size-3 shrink-0" />
                        Attachment
                    </span>
                </span>
            </button>

            <!-- Attachments -->
            <MessageAttachments :attachments="attachments" :is-sent="isSent" />

            <!-- Message text -->
            <p v-if="message" class="text-[15px] leading-[1.45] whitespace-pre-line wrap-anywhere">{{ message }}</p>

            <!-- Existing reactions are quick-toggle buttons; adding the first reaction stays in the context menu. -->
            <div v-if="reactionEntries.length || time" class="flex items-end justify-between gap-3 mt-1.5 min-h-4">
                <div v-if="reactionEntries.length" class="flex flex-wrap items-center gap-1 min-w-0">
                    <button v-for="([emoji, count]) in reactionEntries" :key="emoji" type="button"
                        class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-xs leading-none font-medium ring-1 transition-all cursor-pointer hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-400"
                        :class="reactions?.my_reaction === emoji
                            ? (isSent
                                ? 'bg-white/15 text-white ring-white/40 hover:bg-white/35'
                                : 'bg-blue-200 text-blue-700 ring-blue-200 hover:bg-blue-100 dark:bg-blue-500/70 dark:text-blue-300 dark:ring-blue-400/30 dark:hover:bg-blue-500/30')
                            : (isSent
                                ? 'bg-black/25 text-white/90 ring-white/15 hover:bg-black/20'
                                : 'bg-gray-100 text-gray-700 ring-gray-200 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-600')"
                        :aria-label="`${emoji}, ${count} reaction${count === 1 ? '' : 's'}. ${reactions?.my_reaction === emoji ? 'Remove your reaction' : 'React with this emoji'}`"
                        :aria-pressed="reactions?.my_reaction === emoji" @click.stop="emit('react', emoji)">
                        <span class="text-sm leading-none">{{ emoji }}</span>
                        <span class="tabular-nums">{{ count }}</span>
                    </button>
                </div>
                <div class="flex items-center gap-1.5 ml-auto shrink-0 select-none translate-y-px">
                    <span class="flex items-center gap-1 text-[10.5px] leading-none tabular-nums"
                        :class="isSent ? 'text-white/70' : 'text-gray-400 dark:text-gray-500'">
                        <span v-if="isEdited" class="italic">edited</span>
                        <span>{{ formattedTime }}</span>
                    </span>
                </div>
            </div>

        </div>
    </div>
</template>