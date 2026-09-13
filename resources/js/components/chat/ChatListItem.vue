<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { formattedTimestamp } from '@/helpers/time';
import { useContextMenu } from '@/composables/useContextMenu';
import ContextMenu from '@/components/common/ContextMenu.vue';
import type { Chat } from '@/types';

defineProps<{
    chat: Chat;
    currentChat: Chat | null;
}>();

const { isOpen, x, y, open, close } = useContextMenu();
</script>

<template>
    <div>
        <RouterLink
            :to="`/users/${chat.slug}`"
            :class="{ '!bg-blue-600 text-gray-100': currentChat?.slug === chat.slug }"
            class="flex items-center px-2 py-3 align-middle border-b border-gray-100 dark:border-gray-600"
            @contextmenu.prevent="open"
        >
            <img src="/storage/default-profile.png" alt="profile-image" class="w-10 h-10 mr-2" />
            <div class="flex flex-col w-full h-full overflow-hidden">
                <div class="flex flex-row">
                    <h3 class="text-xl font-normal truncate">{{ chat.name }}</h3>
                    <p class="self-start py-1.5 ml-auto text-xs text-nowrap">
                        {{ chat.last_message_created_at ? formattedTimestamp(chat.last_message_created_at) : '' }}
                    </p>
                </div>
                <div class="flex flex-row items-center">
                    <p class="py-1 mr-2 text-sm truncate">{{ chat.last_message == '' ? (chat.latest_message_attachments_count + ((chat.latest_message_attachments_count>1) ? ' files' : ' file')) : chat.last_message }}</p>
                    <p
                        v-show="(chat.unseen_messages_count ?? 0) > 0"
                        class="ml-auto mr-1 text-sm text-center text-white bg-gray-400 rounded-full min-w-5 min-h-5"
                    >
                        {{ chat.unseen_messages_count ?? 0 }}
                    </p>
                </div>
            </div>
        </RouterLink>

        <ContextMenu :is-open="isOpen" :x="x" :y="y" @close="close">
            <ul>
                <li>
                    <a href="#" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">Mute</a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">Archive</a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">Edit</a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">Delete</a>
                </li>
            </ul>
        </ContextMenu>
    </div>
</template>
