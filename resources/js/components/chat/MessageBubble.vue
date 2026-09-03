<script setup lang="ts">
import { useDateFormat } from '@vueuse/core';
import { useContextMenu } from '@/composables/useContextMenu';
import ContextMenu from '@/components/common/ContextMenu.vue';
import ForwardIcon from '@/assets/svgs/Forward.svg';
import ReplyIcon from '@/assets/svgs/Reply.svg';
import CopyIcon from '@/assets/svgs/Copy.svg';
import FlagIcon from '@/assets/svgs/Flag.svg';
import TrashIcon from '@/assets/svgs/Trash.svg';

defineProps<{
    id?: number;
    message: string;
    time: string;
    isSent?: boolean;
}>();

const { isOpen, x, y, open, close } = useContextMenu();
</script>

<template>
    <div class="z-10 flex items-center px-3 my-2 chat" :class="{ 'flex-row-reverse': isSent }">
        <ContextMenu :is-open="isOpen" :x="x" :y="y" @close="close">
            <ul>
                <li>
                    <a href="#" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">
                        <ReplyIcon class="w-4 h-4 mr-2" />
                        Reply
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">
                        <ForwardIcon class="w-4 h-4 mr-2" />
                        Forward
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">
                        <CopyIcon class="w-4 h-4 mr-2" />
                        Copy
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">
                        <FlagIcon class="w-4 h-4 mr-2" />
                        Report
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">
                        <TrashIcon class="w-4 h-4 mr-2" />
                        Delete
                    </a>
                </li>
            </ul>
        </ContextMenu>

        <div
            class="flex flex-col p-2 rounded-md min-w-32"
            :class="{
                'text-gray-200 ml-6 bg-blue-700': isSent,
                'mr-6 bg-white dark:bg-gray-600': !isSent,
            }"
            @contextmenu.prevent="open"
        >
            <p class="pb-1 text-base whitespace-pre-line wrap-break-word">{{ message }}</p>
            <p class="self-end ml-2 text-sm leading-none">
                {{ useDateFormat(time, 'HH:mm A') }}
            </p>
        </div>
    </div>
</template>
