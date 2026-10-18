<script setup lang="ts">
import { computed, onBeforeUnmount, watch, type Component } from 'vue';
import { onKeyStroke, useDark } from '@vueuse/core';
import useAuthStore from '@/stores/auth';
import ContactsIcon from '@/assets/svgs/Contacts.svg';
import UserPlusIcon from '@/assets/svgs/UserPlus.svg';
import SunIcon from '@/assets/svgs/Sun.svg';
import MoonIcon from '@/assets/svgs/Moon.svg';
import SettingsIcon from '@/assets/svgs/Settings.svg';
import LogoutIcon from '@/assets/svgs/Logout.svg';
import Avatar from '../chat/Avatar.vue';
import { createOverlayId, registerOverlay, unregisterOverlay } from '@/composables/useOverlayManager';

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    (e: 'openContacts'): void;
    (e: 'openAddContact'): void;
    (e: 'openSetting'): void;
    (e: 'logout'): void;
    (e: 'openProfile'): void; // NEW: tap the header to edit your profile
}>();

const auth = useAuthStore();
const isDark = useDark();

type Item = {
    id: 'contacts' | 'addContact' | 'settings';
    label: string;
    icon: Component;
    iconClass: string; // your SVGs mix stroke-based and fill-based icons
    run: () => void;
};

const mainItems = computed<Item[]>(() => [
    { id: 'contacts', label: 'Contacts', icon: ContactsIcon, iconClass: 'stroke-current', run: () => emit('openContacts') },
    { id: 'addContact', label: 'Add contact', icon: UserPlusIcon, iconClass: 'stroke-current', run: () => emit('openAddContact') },
    { id: 'settings', label: 'Settings', icon: SettingsIcon, iconClass: 'fill-current', run: () => emit('openSetting') },
]);

function pick(item: Item) {
    open.value = false;
    item.run();
}

function signOut() {
    open.value = false;
    emit('logout');
}

const itemClass = 'flex items-center w-full gap-4 px-4 py-3 text-base text-left text-gray-700 transition-colors rounded-xl dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500';

const overlayId = createOverlayId('sidebar');

function closeSidebar(): void {
    open.value = false;
}

watch(
    open,
    (isOpen) => {
        if (isOpen) {
            registerOverlay(overlayId, 'overlay', closeSidebar);
        } else {
            unregisterOverlay(overlayId);
        }
    },
    { flush: 'sync' },
);

onBeforeUnmount(() => {
    unregisterOverlay(overlayId);
});
</script>

<template>
    <div>
        <!-- Backdrop -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition-opacity duration-200"
            leave-to-class="opacity-0" leave-active-class="transition-opacity duration-200">
            <div v-show="open" class="absolute inset-0 z-51 bg-black/40 backdrop-blur-[2px]" aria-hidden="true"
                @click="open = false"></div>
        </Transition>

        <!-- Drawer -->
        <Transition enter-from-class="-translate-x-full" enter-active-class="transition-transform duration-300 ease-out"
            leave-to-class="-translate-x-full" leave-active-class="transition-transform duration-200 ease-in">
            <aside v-show="open"
                class="absolute top-0 left-0 z-52 flex flex-col h-full bg-white shadow-2xl w-72 max-w-[85%] dark:bg-gray-800"
                aria-label="Main menu">
                <!-- Profile header -->
                <button type="button"
                    class="flex items-center gap-3 px-4 pt-6 pb-4 text-left transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                    @click="open = false; emit('openProfile')">
                    <!-- <img src="/storage/default-profile.png" alt=""
                        class="object-cover rounded-full size-14" /> -->
                    <Avatar :src="'/storage/default-profile.png'" class="object-cover rounded-full size-14"/>
                    <span class="flex flex-col min-w-0">
                        <span class="text-lg font-medium text-gray-900 truncate dark:text-gray-100">
                            {{ auth.user?.name }}
                        </span>
                        <span class="text-sm text-gray-500 truncate dark:text-gray-400">@{{ auth.user?.username
                            }}</span>
                    </span>
                </button>

                <!-- Main navigation -->
                <nav class="flex flex-col gap-1 px-2 py-2 border-t border-gray-100 dark:border-gray-700"
                    aria-label="Menu">
                    <button v-for="item in mainItems" :key="item.id" type="button" :class="itemClass"
                        @click="pick(item)">
                        <component :is="item.icon" class="size-6 shrink-0 text-gray-500 dark:text-gray-400"
                            :class="item.iconClass" />
                        {{ item.label }}
                    </button>
                </nav>

                <!-- Bottom section -->
                <div class="flex flex-col gap-1 px-2 py-2 mt-auto border-t border-gray-100 dark:border-gray-700">
                    <button type="button" role="switch" :aria-checked="isDark" :class="itemClass"
                        @click="isDark = !isDark">
                        <SunIcon v-if="isDark" class="size-6 shrink-0 stroke-gray-300" />
                        <MoonIcon v-else class="size-6 shrink-0 stroke-gray-500" />
                        <span class="flex-1">Dark mode</span>
                        <span class="relative inline-flex h-6 transition-colors rounded-full w-10 shrink-0"
                            :class="isDark ? 'bg-blue-600' : 'bg-gray-300'" aria-hidden="true">
                            <span
                                class="absolute top-0.5 left-0.5 size-5 bg-white rounded-full shadow transition-transform"
                                :class="isDark ? 'translate-x-4' : ''"></span>
                        </span>
                    </button>

                    <button type="button"
                        :class="[itemClass, '!text-red-600 dark:!text-red-400 hover:!bg-red-50 dark:hover:!bg-red-500/10']"
                        @click="signOut">
                        <LogoutIcon class="size-6 shrink-0 fill-current" />
                        Sign out
                    </button>
                </div>
            </aside>
        </Transition>
    </div>
</template>