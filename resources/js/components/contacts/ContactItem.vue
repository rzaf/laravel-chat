<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { useConfirm } from '@/composables/useConfirm';
import ThreeDotsIcon from '@/assets/svgs/ThreeDots.svg';
import TrashIcon from '@/assets/svgs/Trash.svg';
import EditIcon from '@/assets/svgs/Edit.svg';
import ForwardIcon from '@/assets/svgs/Forward.svg';
import type { Contact } from '@/types';
import Avatar from '../chat/Avatar.vue';
import ContextMenu from '../common/ContextMenu.vue';
import { useContextMenu } from '@/composables/useContextMenu.ts';
import { nextTick, ref } from 'vue';

const props = defineProps<{ contact: Contact }>();
const { isOpen: isMenuOpen, x, y, open, close } = useContextMenu();

const emit = defineEmits<{
    (e: 'closeContactsModals'): void;
    (e: 'openEditContactModal', username: string, name?: string | null): void;
    (e: 'deleteContact', username: string): void;
}>();

const { confirm } = useConfirm();

function handleEdit(): void {
    // close();
    emit('openEditContactModal', props.contact.user.username, props.contact.name);
}

function handleOpenChat(): void {
    close();
    emit('closeContactsModals');
}

function handleDelete(): void {
    // close();
    confirm(`Are you sure you want to delete ${props.contact.user.username}?`).then((isConfirmed) => {
        if (isConfirmed) {
            emit('deleteContact', props.contact.user.username);
        }
    });
}

function handleMenuOpen(event: MouseEvent): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();

    open(
        new MouseEvent('click', {
            clientX: rect.left,
            clientY: rect.top + rect.height / 2,
            bubbles: true,
            cancelable: true,
        }),
    );
}
const menuTrigger = ref<HTMLButtonElement | null>(null);

function handleMenuClose(): void {
    close();

    nextTick(() => {
        menuTrigger.value?.focus({ preventScroll: true });
    });
}
</script>

<template>
    <div class="relative flex items-center pr-2 transition-colors rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700"
        :class="{ 'z-50': isMenuOpen }">
        <RouterLink :to="`/users/${contact.user.username}`"
            class="flex items-center flex-1 min-w-0 gap-3 px-2 py-2.5 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            @click="handleOpenChat">
            <Avatar class="size-12 shrink-0" :src="contact.user.profile_photo || '/storage/default-profile.png'" />
            <span class="flex flex-col min-w-0">
                <span class="text-base font-medium text-gray-900 truncate dark:text-gray-100">
                    {{ contact.name || contact.user.name || '' }}
                </span>
                <span class="text-sm text-gray-500 truncate dark:text-gray-400">@{{ contact.user.username }}</span>
            </span>
        </RouterLink>

        <div class="relative">
            <button ref="menuTrigger" type="button" aria-label="Contact options" aria-haspopup="menu"
                :aria-expanded="isMenuOpen"
                class="grid transition-colors rounded-full size-9 place-items-center hover:bg-gray-200 dark:hover:bg-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                @click.stop.prevent="handleMenuOpen">
                <ThreeDotsIcon class="size-5 fill-gray-500 dark:fill-gray-300" />
            </button>

            <!-- Context Menu Component -->
            <ContextMenu :is-open="isMenuOpen" :x="x" :y="y" @close="handleMenuClose" :align="'right'">
                <div class="py-1 min-w-[140px]">
                    <button type="button"
                        class="flex items-center w-full px-3 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors"
                        @click.stop="handleOpenChat">
                        <ForwardIcon class="size-4 mr-2.5 text-gray-500 dark:text-gray-400" />
                        Open Chat
                    </button>

                    <button type="button"
                        class="flex items-center w-full px-3 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors"
                        @click.stop="handleEdit">
                        <EditIcon class="size-4 mr-2.5 text-gray-500 dark:text-gray-400" />
                        Edit
                    </button>

                    <button type="button"
                        class="flex items-center w-full px-3 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
                        @click.stop="handleDelete">
                        <TrashIcon class="size-4 mr-2.5 text-red-500 dark:text-red-400" />
                        Delete
                    </button>
                </div>
            </ContextMenu>
        </div>
    </div>
</template>