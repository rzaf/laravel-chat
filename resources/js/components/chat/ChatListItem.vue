<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { formattedTimestamp } from '@/helpers/time';
import { useContextMenu } from '@/composables/useContextMenu';
import ContextMenu from '@/components/common/ContextMenu.vue';
// import BellSlashIcon from '@/assets/svgs/BellSlash.svg';
import MuteXIcon from '@/assets/svgs/MuteX.svg';
import MuteOffIcon from '@/assets/svgs/MuteOff.svg';
import PinIcon from '@/assets/svgs/Pin.svg';
import PinOffIcon from '@/assets/svgs/PinOff.svg';
import ArchiveBoxIcon from '@/assets/svgs/ArchiveBox.svg';
import ArchiveRestoreIcon from '@/assets/svgs/ArchiveRestore.svg';
import TrashIcon from '@/assets/svgs/Trash.svg';
import RefreshCwIcon from '@/assets/svgs/RefreshCw.svg';
import CircleCheckIcon from '@/assets/svgs/CircleCheck.svg';
import type { Chat, MuteDuration } from '@/types';
import Avatar from './Avatar.vue';

// export type MuteDuration = '15m' | '1h' | '8h' | '24h' | '1w' | 'forever' | null;

const props = defineProps<{
    chat: Chat;
    currentChat: Chat | null;
}>();

const emit = defineEmits<{
    (e: 'mute', chat: Chat, duration?: MuteDuration): void;
    (e: 'pin', chat: Chat): void;
    (e: 'archive', chat: Chat): void;
    (e: 'markAsRead', chat: Chat): void;
    (e: 'clearHistory', chat: Chat): void;
    (e: 'delete', chat: Chat): void;
}>();

const { isOpen, x, y, open, close } = useContextMenu();

const isActive = computed(() => props.currentChat?.slug === props.chat.slug);
const unseen = computed(() => props.chat.unseen_messages_count ?? 0);
const attachments = computed(() => props.chat.latest_message_attachments_count ?? 0);

const preview = computed(() => {
    if (props.chat.is_typing) return 'typing…';
    if (props.chat.draft) return props.chat.draft;
    if (props.chat.last_message) return props.chat.last_message;
    if (attachments.value > 0) return `${attachments.value} ${attachments.value === 1 ? 'file' : 'files'}`;
    return 'No messages yet';
});

const previewPrefix = computed(() => {
    if (props.chat.is_typing) return '';
    if (props.chat.draft) return 'Draft: ';
    if (props.chat.last_message_is_mine) return 'You: ';
    return '';
});

const muteOptions: { label: string; value: MuteDuration }[] = [
    { label: 'For 15 minutes', value: '15m' },
    { label: 'For 1 hour', value: '1h' },
    { label: 'For 8 hours', value: '8h' },
    { label: 'For 24 hours', value: '24h' },
    { label: 'For 1 week', value: '1w' },
    { label: 'Until I turn it back on', value: 'forever' },
];

function act(action: 'mute' | 'pin' | 'archive' | 'markAsRead' | 'clearHistory' | 'delete', duration?: MuteDuration) {
    if (action === 'mute') emit('mute', props.chat, duration);
    else if (action === 'pin') emit('pin', props.chat);
    else if (action === 'archive') emit('archive', props.chat);
    else if (action === 'markAsRead') emit('markAsRead', props.chat);
    else if (action === 'clearHistory') emit('clearHistory', props.chat);
    else emit('delete', props.chat);

    close();
}
</script>

<template>
    <div class="px-2 py-0.5">
        <RouterLink :to="`/users/${chat.slug}`"
            class="relative flex items-center gap-3 px-2.5 py-2.5 transition-colors rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            :class="isActive
                ? 'lg:bg-blue-600 lg:text-white'
                : 'text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700'
                " @contextmenu.prevent="open">
            <!-- Avatar + presence -->
            <div class="relative shrink-0">
                <Avatar class="w-13 h-13" :src="chat.profile_photo || '/storage/default-profile.png'" />
                <span v-if="chat.is_online"
                    class="absolute bottom-0 right-1.5 bg-green-500 border-4 rounded-full size-4.5"
                    :class="isActive ? 'lg:border-blue-600' : 'border-white dark:border-gray-800'"
                    title="Online"></span>
            </div>

            <div class="flex flex-col flex-1 min-w-0 gap-0.5">
                <!-- Row 1: name + pin + mute + time -->
                <div class="flex items-center gap-2">
                    <h3 class="text-base font-medium truncate" :class="{ 'font-semibold': unseen > 0 && !chat.muted }">
                        {{ chat.name }}
                    </h3>

                    <PinIcon v-if="chat.pinned" class="size-3.5 shrink-0 rotate-45 opacity-50" aria-label="Pinned" />

                    <MuteXIcon v-if="chat.muted" class="size-4 shrink-0 opacity-50" stroke-width="2" />

                    <time class="ml-auto text-xs whitespace-nowrap" dir="rtl"
                        :class="isActive ? 'lg:text-blue-100' : 'text-gray-500 dark:text-gray-400'">
                        {{ chat.last_message_created_at ? formattedTimestamp(chat.last_message_created_at) : '' }}
                    </time>
                </div>

                <!-- Row 2: preview + badges -->
                <div class="flex items-center gap-2">
                    <p class="text-sm truncate" :class="isActive
                        ? 'lg:text-blue-100'
                        : chat.is_typing
                            ? 'text-blue-600 dark:text-blue-400'
                            : 'text-gray-500 dark:text-gray-400'
                        ">
                        <span v-if="previewPrefix" :class="{ 'text-red-500': chat.draft && !isActive }">
                            {{ previewPrefix }}
                        </span>
                        {{ preview }}
                    </p>

                    <div class="flex items-center gap-1.5 ml-auto shrink-0">
                        <span v-if="unseen > 0"
                            class="grid px-1.5 text-xs font-medium rounded-full min-w-5 h-5 place-items-center" :class="isActive
                                ? 'lg:bg-white lg:text-blue-600'
                                : chat.muted
                                    ? 'bg-gray-400 text-white'
                                    : 'bg-blue-600 text-white'
                                " :aria-label="`${unseen} unread messages`">
                            {{ unseen > 99 ? '99+' : unseen }}
                        </span>
                    </div>
                </div>
            </div>
        </RouterLink>

        <ContextMenu :is-open="isOpen" :x="x" :y="y" @close="close">
            <ul class="py-1 text-sm min-w-44">
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2.5 px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-600"
                        @click="act('pin')">
                        <component :is="chat.pinned ? PinOffIcon : PinIcon" class="size-4 opacity-70 shrink-0" />
                        <span>{{ chat.pinned ? 'Unpin' : 'Pin to top' }}</span>
                    </button>
                </li>

                <!-- Mute with Hoverable Submenu -->
                <li v-if="chat.muted">
                    <button type="button"
                        class="flex items-center w-full gap-2.5 px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-600"
                        @click="act('mute', null)">
                        <MuteOffIcon class="size-4 opacity-70 shrink-0" />
                        <span>Unmute</span>
                    </button>
                </li>
                <!-- Mute with Hoverable Submenu -->
                <li v-else class="relative group">
                    <button type="button"
                        class="flex items-center justify-between w-full px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-600">
                        <div class="flex items-center gap-2.5">
                            <MuteXIcon class="size-4 opacity-70 shrink-0" />
                            <span>Mute</span>
                        </div>
                        <!-- Chevron Right Icon -->
                        <svg class="size-3.5 opacity-60" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>

                    <!-- Invisible padding bridge + Submenu container -->
                    <div class="hidden group-hover:block group-focus-within:block absolute left-full top-0 pl-1">
                        <div
                            class="py-1 w-48 bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-lg shadow-lg">
                            <ul>
                                <li v-for="option in muteOptions" :key="option.value ?? 'unmute'">
                                    <button type="button"
                                        class="w-full px-3 py-1.5 text-left text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700"
                                        @click="act('mute', option.value)">
                                        {{ option.label }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </li>

                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2.5 px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-600"
                        @click="act('archive')">
                        <component :is="chat.archived ? ArchiveRestoreIcon : ArchiveBoxIcon"
                            class="size-4 opacity-70 shrink-0" />
                        <span>{{ chat.archived ? 'Unarchive' : 'Archive' }}</span>
                    </button>
                </li>
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2.5 px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-600"
                        @click="act('markAsRead')">
                        <CircleCheckIcon class="size-4 opacity-70 shrink-0" />
                        <span>Mark as read</span>
                    </button>
                </li>
                <li>
                    <button type="button"
                        class="flex items-center w-full gap-2.5 px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-600"
                        @click="act('clearHistory')">
                        <RefreshCwIcon class="size-4 opacity-70 shrink-0" />
                        <span>Clear History</span>
                    </button>
                </li>
                <li class="mt-1 border-t border-gray-100 dark:border-gray-600">
                    <button type="button"
                        class="flex items-center w-full gap-2.5 px-3 py-2 text-left text-red-600 hover:bg-red-50 dark:hover:bg-gray-600"
                        @click="act('delete')">
                        <TrashIcon class="size-4 opacity-70 shrink-0" />
                        <span>Delete</span>
                    </button>
                </li>
            </ul>
        </ContextMenu>
    </div>
</template>