<script setup lang="ts">
import { useDark } from '@vueuse/core';
import useAuthStore from '@/stores/auth';

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
                        <!-- <svg class="mx-4 stroke-gray-500" width="28px" height="28px"  xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                        </svg> -->
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8 mx-4 stroke-gray-500" >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>


                        Contacts
                    </a>
                    <!-- <a @click="open = false; emit('openContacts')">
                        <svg class="mx-4 fill-gray-500" width="28px" height="28px" viewBox="0 0 20 20">
                            <g>
                                <defs> </defs>
                                <g>
                                    <g transform="translate(-420.000000, -2159.000000)">
                                        <g transform="translate(56.000000, 160.000000)">
                                            <path
                                                d="M374,2009 C371.794,2009 370,2007.206 370,2005 C370,2002.794 371.794,2001 374,2001 C376.206,2001 378,2002.794 378,2005 C378,2007.206 376.206,2009 374,2009 M377.758,2009.673 C379.124,2008.574 380,2006.89 380,2005 C380,2001.686 377.314,1999 374,1999 C370.686,1999 368,2001.686 368,2005 C368,2006.89 368.876,2008.574 370.242,2009.673 C366.583,2011.048 364,2014.445 364,2019 L366,2019 C366,2014 369.589,2011 374,2011 C378.411,2011 382,2014 382,2019 L384,2019 C384,2014.445 381.417,2011.048 377.758,2009.673">
                                            </path>
                                        </g>
                                    </g>
                                </g>
                            </g>
                        </svg>
                        Old Contacts Icon
                    </a> -->

                    <a @click="open = false; emit('openAddContact')">
                        <!-- <svg class="mx-4 fill-gray-500" width="28" height="28" viewBox="0 0 16 16">
                            <path
                                d="M8 1a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm0 1a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm.03 9c-2.88 0-4.43.27-5.58 1.13-.57.44-.95 1.03-1.17 1.72C1.07 14.5 1 15.02 1 15.94L2 16c0-.87.07-1.3.24-1.83.16-.53.4-.9.8-1.2.81-.62 2.16-.94 4.98-.94.77 0 1.4.03 1.97.08v-1.02c-.65-.05-1.31-.06-1.96-.06z"
                            />
                            <path d="M13 11v2h-2v1h2v2h1v-2h2v-1h-2v-2z" />
                        </svg> -->
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8 mx-4 stroke-gray-500">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
                        </svg>

                        Add contact
                    </a>

                    <a class="!mt-auto" @click="isDark = !isDark">
                        <!-- <svg class="mx-4 fill-gray-500" width="28" height="28" viewBox="0 0 16 16">
                            <path
                                d="M8 3a5 5 0 1 0 0 10A5 5 0 0 0 8 3zm0 1v8a4 4 0 0 1 0-8zM8.5 0h-1v2h1zm4.8 2l-1.41 1.41.71.71 1.41-1.41zm-10.6 0l-.71.71L3.4 4.11l.71-.71zM16 7.5h-2v1h2zm-14 0H0v1h2zm10.6 4.39l-.71.71 1.41 1.41.71-.71zm-9.2 0L1.99 13.3l.71.71 1.41-1.41zm5.1 2.11h-1v2h1z"
                            />
                        </svg> -->

                        <svg v-show="isDark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="mx-4 stroke-gray-500 size-8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                        <svg v-show="!isDark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="mx-4 stroke-gray-500 size-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                        </svg>


                        {{ isDark ? 'light mode' : 'dark mode' }}
                    </a>

                    <a @click="open = false; emit('openSetting')">
                        <svg class="mx-4 fill-gray-500" width="28" height="28" fill-rule="evenodd" viewBox="0 0 24 24">
                            <path
                                d="M12 8.75a3.25 3.25 0 1 0 0 6.5 3.25 3.25 0 0 0 0-6.5zM20.1 9.22c-1.81 0-2.55-1.28-1.65-2.85.52-.91.21-2.07-.7-2.59l-1.73-.99a1.9 1.9 0 0 0-2.6.59l-.11.19c-.9 1.57-2.38 1.57-3.29 0l-.11-.19a1.9 1.9 0 0 0-2.59-.59l-1.73.99a1.9 1.9 0 0 0-.7 2.6c.91 1.56.17 2.84-1.64 2.84A1.9 1.9 0 0 0 2 11.12v1.76c0 1.04.85 1.9 1.9 1.9 1.81 0 2.55 1.28 1.64 2.85a1.9 1.9 0 0 0 .7 2.59l1.73.99a1.9 1.9 0 0 0 2.59-.59l.11-.19c.9-1.57 2.38-1.57 3.29 0l.11.19a1.9 1.9 0 0 0 2.6.59l1.73-.99c.91-.52 1.22-1.69.7-2.59-.91-1.57-.17-2.85 1.64-2.85 1.04 0 1.9-.85 1.9-1.9v-1.76c0-1.04-.85-1.9-1.9-1.9z"
                            />
                        </svg>
                        setting
                    </a>

                    <a @click="emit('logout')">
                        <svg class="mx-4 fill-gray-500" width="28" height="28" viewBox="0 0 24 24">
                            <path
                                d="M6 3a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3h11a1 1 0 1 0 0-2H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h11a1 1 0 1 0 0-2H6zm9.7 4.29a1 1 0 0 0-1.4 1.42L16.58 11H8a1 1 0 1 0 0 2h8.58l-2.3 2.29a1 1 0 0 0 1.42 1.42l4-4a1 1 0 0 0 0-1.42l-4-4z"
                            />
                        </svg>
                        Sign out
                    </a>
                </div>
            </div>
        </Transition>
    </div>
</template>
