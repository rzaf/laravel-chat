<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
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

function requestDelete(): void {
    isMenuOpen.value = false;
    confirm(`Are you sure you want to delete ${props.contact.user.username}?`, () =>
        emit('deleteContact', props.contact.user.username),
    );
}
</script>

<template>
    <RouterLink
        :to="`/users/${contact.user.username}`"
        class="relative flex items-center px-2 py-3 align-middle border-b border-gray-100 cursor-pointer dark:border-gray-600 hover:bg-gray-200 dark:hover:bg-gray-700"
        @click="emit('closeContactsModals')"
    >
        <Avatar class="w-12 h-12 mr-2" :src="contact.user.profile_photo || '/storage/default-profile.png'"></Avatar>

        <div class="flex items-center w-full">
            <div class="flex flex-col w-full h-full overflow-hidden">
                <h3 class="text-lg font-normal truncate">{{ contact.name || contact.user.name || '' }}</h3>
                <p class="py-1 text-sm truncate">@{{ contact.user.username }}</p>
            </div>

            <div class="relative ml-auto mr-4" @click.stop.prevent="isMenuOpen = true">
                <ThreeDotsIcon
                    class="p-2 rounded-md w-9 h-9 fill-gray-500 hover:bg-gray-300 dark:hover:bg-gray-600 dark:fill-gray-300"
                />

                <div
                    v-show="isMenuOpen"
                    class="absolute top-0 z-[800] flex flex-col bg-white rounded-lg shadow-lg right-full dark:bg-gray-600"
                >
                    <ul>
                        <li>
                            <a href="/users/{{contact.user.username}}" class="flex items-center p-2 hover:bg-gray-200 dark:hover:bg-gray-500">
                                <ForwardIcon class="w-4 h-4 mr-2" />
                                Open
                            </a>
                        </li>
                        <li>
                            <a
                                class="flex items-center p-2 cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-500"
                                @click.stop.prevent="
                                    isMenuOpen = false;
                                    emit('openEditContactModal', contact.user.username, contact.name)
                                "
                            >
                                <EditIcon class="w-4 h-4 mr-2" />
                                Edit
                            </a>
                        </li>
                        <li>
                            <a
                                class="flex items-center p-2 cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-500"
                                @click.stop.prevent="requestDelete"
                            >
                                <TrashIcon class="w-4 h-4 mr-2" />
                                Delete
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div
            v-show="isMenuOpen"
            class="fixed inset-0 z-40 w-full h-full"
            @click.stop.prevent="isMenuOpen = false"
        ></div>
    </RouterLink>
</template>
