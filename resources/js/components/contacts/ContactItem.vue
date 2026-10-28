<script setup lang="ts">
import { RouterLink } from 'vue-router';
import ThreeDotsIcon from '@/assets/svgs/ThreeDots.svg';
import type { Contact } from '@/types';
import Avatar from '../chat/Avatar.vue';

const props = defineProps<{
    contact: Contact;
    isMenuOpen?: boolean;
}>();

const emit = defineEmits<{
    (e: 'closeContactsModals'): void;
    (e: 'openEditContactModal', username: string, name?: string | null): void;
    (e: 'deleteContact', username: string): void;
    (e: 'openMenu', contact: Contact, event: MouseEvent): void;
}>();

function handleOpenChat(): void {
    emit('closeContactsModals');
}

function handleMenuOpen(event: MouseEvent): void {
    const target = event.currentTarget as HTMLElement;
    const rect = target.getBoundingClientRect();
    const syntheticEvent = new MouseEvent('click', {
        clientX: rect.left,
        clientY: rect.top + rect.height / 2,
        bubbles: true,
        cancelable: true,
    });
    emit('openMenu', props.contact, syntheticEvent);
}
</script>

<template>
    <div class="relative flex items-center pr-2 transition-colors rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700"
        :class="[
            isMenuOpen ? 'bg-gray-100 dark:bg-gray-700 z-50' : ''
        ]" >
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
            <button type="button" aria-label="Contact options" aria-haspopup="menu" :aria-expanded="isMenuOpen"
                class="grid transition-colors rounded-full size-9 place-items-center hover:bg-gray-200 dark:hover:bg-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500"
                @click.stop.prevent="handleMenuOpen">
                <ThreeDotsIcon class="size-5 fill-gray-500 dark:fill-gray-300" />
            </button>
        </div>
    </div>
</template>