<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { formattedTimestamp } from '@/helpers/time';
import MuteXIcon from '@/assets/svgs/MuteX.svg';
import PinIcon from '@/assets/svgs/Pin.svg';
import type { Chat } from '@/types';
import Avatar from './Avatar.vue';

const props = defineProps<{
    chat: Chat;
    currentChat: Chat | null;
    isMenuOpen?: boolean;
}>();

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
</script>

<template>
    <div class="px-2 py-0.5">
        <RouterLink :to="`/users/${chat.slug}`"
            class="relative flex items-center gap-3 px-2.5 py-2.5 transition-colors rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            :class="[
                isActive
                    ? 'lg:bg-blue-600 lg:text-white'
                    : 'text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700',
                isMenuOpen && !isActive ? 'bg-gray-100 dark:bg-gray-700' : ''
            ]">
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
    </div>
</template>