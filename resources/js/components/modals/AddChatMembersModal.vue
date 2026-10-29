<!-- src/components/modals/AddChatMembersModal.vue -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { isAxiosError } from 'axios';
import { addChatMembers, searchUsers, getChatContactMemberUsernames } from '@/api';
import { useModalControl } from '@/composables/useModalControl';
import useContactStore from '@/stores/contact';
import Modal from '@/components/common/Modal.vue';
import Avatar from '@/components/chat/Avatar.vue';
import CloseIcon from '@/assets/svgs/Close.svg';
import SearchIcon from '@/assets/svgs/Search.svg';
import type { Chat, User } from '@/types';

const MAX_PER_REQUEST = 50;

const emit = defineEmits<{ (e: 'added', chat: Chat, usernames: string[]): void }>();

const { modal, open: modalOpen, close, toggle, isOpen } = useModalControl();
const contactStore = useContactStore();

const target = ref<Chat | null>(null);
const query = ref('');
const searchTab = ref<'contacts' | 'global'>('contacts');
const selected = ref<Set<string>>(new Set());
const existingMemberUsernames = ref<Set<string>>(new Set());

const globalUsers = ref<User[]>([]);
const searchingGlobal = ref(false);
let searchTimeout: number | null = null;

const submitting = ref(false);
const error = ref('');

const kindLabel = computed(() => (target.value?.type === 'channel' ? 'subscribers' : 'members'));
const contacts = computed(() => contactStore.contacts ?? []);

const visibleContacts = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return contacts.value;
    return contacts.value.filter((c) =>
        [c.name, c.user.name, c.user.username].filter(Boolean).join(' ').toLowerCase().includes(q),
    );
});

watch(query, (val) => {
    if (searchTab.value !== 'global') return;
    if (searchTimeout) window.clearTimeout(searchTimeout);
    const q = val.trim();
    if (!q) {
        globalUsers.value = [];
        return;
    }
    searchTimeout = window.setTimeout(async () => {
        searchingGlobal.value = true;
        try {
            const res = await searchUsers(q, 1, false);
            globalUsers.value = res.data ?? [];
        } catch (e) {
            console.error('Failed to search users', e);
        } finally {
            searchingGlobal.value = false;
        }
    }, 300);
});

const limitReached = computed(() => selected.value.size >= MAX_PER_REQUEST);

function toggleContact(username: string): void {
    if (existingMemberUsernames.value.has(username)) return;
    const next = new Set(selected.value);
    if (next.has(username)) {
        next.delete(username);
    } else if (next.size < MAX_PER_REQUEST) {
        next.add(username);
    }
    selected.value = next;
}

async function open(chat: Chat): Promise<void> {
    target.value = chat;
    query.value = '';
    searchTab.value = 'contacts';
    selected.value = new Set();
    existingMemberUsernames.value = new Set();
    globalUsers.value = [];
    error.value = '';
    submitting.value = false;

    if (contactStore.contacts === null) {
        void contactStore.loadContacts().catch((e) => console.error('Failed to load contacts', e));
    }

    if (chat.id) {
        try {
            const usernames = await getChatContactMemberUsernames(chat.id);
            existingMemberUsernames.value = new Set(usernames);
        } catch (e) {
            console.error('Failed to load contact member usernames', e);
        }
    }

    modalOpen();
}

async function submit(): Promise<void> {
    const chat = target.value;
    if (!chat?.id || selected.value.size === 0 || submitting.value) return;
    submitting.value = true;
    error.value = '';
    try {
        const usernames = [...selected.value];
        const res = await addChatMembers(chat.id, usernames);
        if (chat.members_count != null) {
            chat.members_count += res.added.length;
        }
        emit('added', chat, res.added);
        close();
    } catch (err) {
        if (isAxiosError(err) && err.response?.status === 403) {
            error.value = 'Only admins can add people.';
        } else {
            error.value = 'Could not add people. Please try again.';
        }
        console.error('Failed to add members', err);
    } finally {
        submitting.value = false;
    }
}

defineExpose({ open, close, toggle, isOpen });
</script>

<template>
    <Modal ref="modal" width-class="max-w-[500px] w-[90%]" height-class="h-[90%]! lg:h-[75%]!">
        <div class="flex flex-col h-full">
            <div class="flex items-center justify-between px-5 pt-4 pb-2">
                <div>
                    <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100">Add {{ kindLabel }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ target?.name ?? target?.slug }}</p>
                </div>
                <button type="button" aria-label="Close"
                    class="grid transition-colors rounded-full cursor-pointer size-10 place-items-center hover:bg-gray-100 dark:hover:bg-gray-700"
                    @click="close">
                    <CloseIcon class="size-6 text-gray-500 dark:text-gray-300" />
                </button>
            </div>

            <div class="px-5 pb-3 flex flex-col gap-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">Source:</span>
                    <div class="flex gap-1">
                        <button type="button" class="cursor-pointer px-2.5 py-1 rounded"
                            :class="searchTab === 'contacts' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700'"
                            @click="searchTab = 'contacts'">Contacts</button>
                        <button type="button" class="cursor-pointer px-2.5 py-1 rounded"
                            :class="searchTab === 'global' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700'"
                            @click="searchTab = 'global'">Search All</button>
                    </div>
                </div>
                <div class="relative">
                    <SearchIcon
                        class="absolute -translate-y-1/2 pointer-events-none left-3.5 top-1/2 size-4 text-gray-400" />
                    <input v-model="query" type="search"
                        :placeholder="searchTab === 'contacts' ? 'Search contacts' : 'Search global users'"
                        aria-label="Search users"
                        class="w-full h-11 pl-10 pr-4 text-sm text-gray-900 placeholder-gray-400 transition bg-gray-100 border border-transparent outline-none rounded-full dark:bg-gray-700 dark:text-white focus:bg-white dark:focus:bg-gray-800 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20" />
                </div>
            </div>

            <div class="flex flex-col flex-1 min-h-0 px-3 pb-2 overflow-y-auto scrollable">
                <template v-if="searchTab === 'contacts'">
                    <p v-if="contactStore.isLoading" class="px-6 py-12 text-center text-gray-500">Loading contacts…</p>
                    <p v-else-if="!visibleContacts.length"
                        class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">No matching contacts</p>
                    <label v-for="c in visibleContacts" :key="c.user.username"
                        class="flex items-center gap-3 px-3 py-2.5 transition-colors rounded-xl"
                        :class="existingMemberUsernames.has(c.user.username) ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700'">
                        <input type="checkbox" class="accent-blue-600" :checked="selected.has(c.user.username)"
                            :disabled="existingMemberUsernames.has(c.user.username) || (limitReached && !selected.has(c.user.username))"
                            @change="toggleContact(c.user.username)" />
                        <Avatar class="size-11 shrink-0"
                            :src="c.user.profile_photo || '/storage/default-profile.png'" />
                        <span class="flex flex-col min-w-0 flex-1">
                            <span class="text-base text-gray-900 truncate dark:text-gray-100">{{ c.name || c.user.name
                                }}</span>
                            <span class="text-sm text-gray-500 truncate dark:text-gray-400">@{{ c.user.username
                                }}</span>
                        </span>
                        <span v-if="existingMemberUsernames.has(c.user.username)"
                            class="text-xs text-blue-600 dark:text-blue-400 font-medium px-2 py-0.5 bg-blue-50 dark:bg-blue-950/50 rounded-full">Already
                            member</span>
                    </label>
                </template>

                <template v-else>
                    <p v-if="searchingGlobal" class="px-6 py-12 text-center text-gray-500">Searching users…</p>
                    <p v-else-if="!query.trim()" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">Type a
                        name or username to search.</p>
                    <p v-else-if="!globalUsers.length" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                        No users found</p>
                    <label v-for="u in globalUsers" :key="u.username"
                        class="flex items-center gap-3 px-3 py-2.5 transition-colors rounded-xl"
                        :class="u.username && existingMemberUsernames.has(u.username) ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700'">
                        <input type="checkbox" class="accent-blue-600"
                            :checked="u.username ? selected.has(u.username) : false"
                            :disabled="!u.username || existingMemberUsernames.has(u.username) || (limitReached && !selected.has(u.username))"
                            @change="u.username && toggleContact(u.username)" />
                        <Avatar class="size-11 shrink-0" :src="u.profile_photo || '/storage/default-profile.png'" />
                        <span class="flex flex-col min-w-0 flex-1">
                            <span class="text-base text-gray-900 truncate dark:text-gray-100">{{ u.name }}</span>
                            <span class="text-sm text-gray-500 truncate dark:text-gray-400">@{{ u.username }}</span>
                        </span>
                        <span v-if="u.username && existingMemberUsernames.has(u.username)"
                            class="text-xs text-blue-600 dark:text-blue-400 font-medium px-2 py-0.5 bg-blue-50 dark:bg-blue-950/50 rounded-full">Already
                            member</span>
                    </label>
                </template>
            </div>

            <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700">
                <p v-if="error" role="alert" class="mb-2 text-sm text-red-500">{{ error }}</p>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ selected.size ? `${selected.size} selected` : 'No one selected' }}
                    </span>
                    <button type="button" :disabled="selected.size === 0 || submitting"
                        class="px-5 py-2 text-sm font-medium text-white transition-colors bg-blue-600 rounded-full cursor-pointer hover:bg-blue-700 disabled:opacity-40"
                        @click="submit">
                        {{ submitting ? 'Adding…' : 'Add' }}
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>