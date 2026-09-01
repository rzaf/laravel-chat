<script setup lang="ts">
import { useDark } from '@vueuse/core';
import useAuthStore from '@/stores/auth';
import ContactsIcon from '@/assets/svgs/Contacts.svg';
import AddContactIcon from '@/assets/svgs/AddContact.svg';
import SunIcon from '@/assets/svgs/Sun.svg';
import MoonIcon from '@/assets/svgs/Moon.svg';
import SettingsIcon from '@/assets/svgs/Settings.svg';
import LogoutIcon from '@/assets/svgs/Logout.svg';

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    (e: 'openContacts'): void;
    (e: 'openAddContact'): void;
    (e: 'openSetting'): void;
    (e: 'logout'): void;
}>();

const auth = useAuthStore();
const isDark = useDark();
</script>

<template>
    <div>
        <div
            v-show="open"
            class="absolute top-0 left-0 z-40 w-full h-full backdrop-brightness-50"
            @click="open = false"
        ></div>

        <Transition
            enter-from-class="translate-x-[-80%]"
            enter-active-class="transition duration-300"
            leave-to-class="-translate-x-full"
            leave-active-class="transition duration-300"
        >
            <div
                v-show="open"
                class="absolute top-0 left-0 z-50 flex flex-col w-64 h-full bg-white dark:bg-gray-800"
            >
                <div class="flex items-center p-2 border-b border-gray-200 dark:border-gray-700">
                    <img src="/storage/default-profile.png" alt="profile-image" class="w-12 h-12 mr-3" />
                    <div class="flex flex-col">
                        <h3 class="text-xl font-normal">{{ auth.user?.name }}</h3>
                        <p class="text-base">@{{ auth.user?.username }}</p>
                    </div>
                </div>

                <div
                    class="flex flex-col h-full overflow-y-auto select-none *:flex *:px-2 *:py-3 *:text-xl *:cursor-pointer *:items-center *:hover:bg-gray-200 dark:*:hover:bg-gray-700"
                >
                    <a @click="open = false; emit('openContacts')">
                        <ContactsIcon class="size-8 mx-4 stroke-gray-500" />
                        Contacts
                    </a>

                    <a @click="open = false; emit('openAddContact')">
                        <AddContactIcon class="size-8 mx-4 stroke-gray-500" />
                        Add contact
                    </a>

                    <a class="!mt-auto" @click="isDark = !isDark">
                        <SunIcon v-show="isDark" class="mx-4 stroke-gray-500 size-8" />
                        <MoonIcon v-show="!isDark" class="mx-4 stroke-gray-500 size-6" />

                        {{ isDark ? 'light mode' : 'dark mode' }}
                    </a>

                    <a @click="open = false; emit('openSetting')">
                        <SettingsIcon class="mx-4 fill-gray-500 size-7" />
                        setting
                    </a>

                    <a @click="emit('logout')">
                        <LogoutIcon class="mx-4 fill-gray-500 size-7" />
                        Sign out
                    </a>
                </div>
            </div>
        </Transition>
    </div>
</template>
