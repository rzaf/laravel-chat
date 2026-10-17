<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import { onKeyStroke } from '@vueuse/core';
import { useConfirm } from '@/composables/useConfirm';
import ThreeDotsIcon from '@/assets/svgs/ThreeDots.svg';
import TrashIcon from '@/assets/svgs/Trash.svg';
import EditIcon from '@/assets/svgs/Edit.svg';
import ForwardIcon from '@/assets/svgs/Forward.svg';
import type { Contact } from '@/types';
import Avatar from '../chat/Avatar.vue';

const props = defineProps<{ contact: Contact }>();

const emit = defineEmits<{
    (e: 'closeContactsModals'): void;
    (e: 'openEditContactModal', username: string, name?: string | null): void;
    (e: 'deleteContact', username: string): void;
}>();

const isMenuOpen = ref(false);
const { confirm } = useConfirm();

onKeyStroke('Escape', () => {
    if (isMenuOpen.value) isMenuOpen.value = false;
});

function openEdit(): void {
    isMenuOpen.value = false;
    emit('openEditContactModal', props.contact.user.username, props.contact.name);
}

function openChat(): void {
    isMenuOpen.value = false;
    emit('closeContactsModals');
}

function requestDelete(): void {
    isMenuOpen.value = false;
    confirm(`Are you sure you want to delete ${props.contact.user.username}?`).then((isConfirmed) => {
        if (isConfirmed) {
            emit('deleteContact', props.contact.user.username);
        }
    });
}

const menuItem =
    'flex items-center w-full gap-3 px-3 py-2 text-sm text-left transition-colors hover:bg-gray-100 dark:hover:bg-gray-600';
</script>

<template>
    <!-- The row is a div (not a link) so the menu button isn't nested inside an <a>. -->
    <div class="relative flex items-center pr-2 transition-colors rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700"
        :class="{ 'z-50': isMenuOpen }">
        <RouterLink :to="`/users/${contact.user.username}`"
            class="flex items-center flex-1 min-w-0 gap-3 px-2 py-2.5 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            @click="openChat">
            <Avatar class="size-12 shrink-0" :src="contact.user.profile_photo || '/storage/default-profile.png'" />
            <span class="flex flex-col min-w-0">
                <span class="text-base font-medium text-gray-900 truncate dark:text-gray-100">
                    {{ contact.name || contact.user.name || '' }}
                </span>
                <span class="text-sm text-gray-500 truncate dark:text-gray-400">@{{ contact.user.username }}</span>
            </span>
        </RouterLink>

        <div class="relative">
            <button type="button" aria-label="Contact options" aria-haspopup="menu" :aria-expanded="isMenuOpen"
                class="grid transition-colors rounded-full size-9 place-items-center hover:bg-gray-200 dark:hover:bg-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                @click.stop.prevent="isMenuOpen = !isMenuOpen">
                <ThreeDotsIcon class="size-5 fill-gray-500 dark:fill-gray-300" />
            </button>

            <Transition enter-from-class="opacity-0 scale-95"
                enter-active-class="transition duration-100 origin-top-right" leave-to-class="opacity-0 scale-95"
                leave-active-class="transition duration-75 origin-top-right">
                <ul v-show="isMenuOpen" role="menu"
                    class="absolute top-0 z-[800] py-1 overflow-hidden bg-white border border-gray-100 shadow-lg right-full mr-1 min-w-36 rounded-xl dark:bg-gray-700 dark:border-gray-600">
                    <li role="none">
                        <RouterLink role="menuitem" :to="`/users/${contact.user.username}`" :class="menuItem"
                            @click="openChat">
                            <ForwardIcon class="size-4" />
                            Open
                        </RouterLink>
                    </li>
                    <li role="none">
                        <button type="button" role="menuitem" :class="menuItem" @click.stop.prevent="openEdit">
                            <EditIcon class="size-4" />
                            Edit
                        </button>
                    </li>
                    <li role="none" class="mt-1 border-t border-gray-100 dark:border-gray-600">
                        <button type="button" role="menuitem" :class="[menuItem, 'text-red-600 dark:text-red-400']"
                            @click.stop.prevent="requestDelete">
                            <TrashIcon class="size-4" />
                            Delete
                        </button>
                    </li>
                </ul>
            </Transition>
        </div>

        <!-- Click-away layer -->
        <div v-show="isMenuOpen" class="fixed inset-0 z-40" aria-hidden="true" @click.stop.prevent="isMenuOpen = false">
        </div>
    </div>
</template>