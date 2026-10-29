<!-- src/components/modals/CreateChatModal.vue -->
<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import { createChat, searchUsers } from '@/api';
import useChatStore from '@/stores/chat';
import useContactStore from '@/stores/contact';
import { useModalControl } from '@/composables/useModalControl';
import Modal from '@/components/common/Modal.vue';
import Avatar from '@/components/chat/Avatar.vue';
import CloseIcon from '@/assets/svgs/Close.svg';
import UsersIcon from '@/assets/svgs/Users.svg';
import MegaphoneIcon from '@/assets/svgs/Megaphone.svg';
import SearchIcon from '@/assets/svgs/Search.svg';
import type { Chat, User } from '@/types';
import type { CreateChatPayload } from '@/types/api';


type Kind = 'group' | 'channel';
type SearchTab = 'contacts' | 'global';

const MAX_MEMBERS = 50;
const SLUG_PATTERN = /^[A-Za-z0-9_-]{3,127}$/;

const emit = defineEmits<{ (e: 'created', chat: Chat): void }>();

const { modal, open: modalOpen, close, toggle, isOpen } = useModalControl();

const router = useRouter();
const chatStore = useChatStore();
const contactStore = useContactStore();

/* ------------------------------------------------------------------ form */

const kind = ref<Kind>('group');
const name = ref('');
const description = ref('');
const isPrivate = ref(false);
const slug = ref('');
const adminsOnly = ref(false);
const selectedMap = ref<Map<string, User>>(new Map()); // username -> User object
const contactQuery = ref('');
const searchTab = ref<SearchTab>('contacts');

const globalUsers = ref<User[]>([]);
const searchingGlobal = ref(false);
let searchTimeout: number | null = null;

const submitting = ref(false);
const formError = ref('');
const fieldErrors = ref<Record<string, string>>({});

const nameInput = ref<HTMLInputElement | null>(null);

const label = computed(() => (kind.value === 'channel' ? 'channel' : 'group'));
const trimmedName = computed(() => name.value.trim());
const trimmedSlug = computed(() => slug.value.trim());
const slugInvalid = computed(() => !isPrivate.value && trimmedSlug.value !== '' && !SLUG_PATTERN.test(trimmedSlug.value));
const canSubmit = computed(() => trimmedName.value !== '' && !slugInvalid.value && !submitting.value);

function resetForm(type: Kind): void {
    kind.value = type;
    name.value = '';
    description.value = '';
    isPrivate.value = false;
    slug.value = '';
    adminsOnly.value = false;
    selectedMap.value.clear();
    contactQuery.value = '';
    searchTab.value = 'contacts';
    globalUsers.value = [];
    formError.value = '';
    fieldErrors.value = {};
    submitting.value = false;
}

/* --------------------------------------------------------------- contacts & search */

const contacts = computed(() => contactStore.contacts ?? []);

const visibleContacts = computed(() => {
    const q = contactQuery.value.trim().toLowerCase();
    if (!q) return contacts.value;
    return contacts.value.filter((c) =>
        [c.name, c.user.name, c.user.username].filter(Boolean).join(' ').toLowerCase().includes(q),
    );
});

watch(contactQuery, (val) => {
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

const limitReached = computed(() => selectedMap.value.size >= MAX_MEMBERS);

function toggleMember(user: User): void {
    if (!user.username) return;
    const next = new Map(selectedMap.value);
    if (next.has(user.username)) {
        next.delete(user.username);
    } else if (next.size < MAX_MEMBERS) {
        next.set(user.username, user);
    }
    selectedMap.value = next;
}

/* ---------------------------------------------------------------- submit */

async function submit(): Promise<void> {
    if (!canSubmit.value) return;
    submitting.value = true;
    formError.value = '';
    fieldErrors.value = {};

    const payload: CreateChatPayload = {
        type: kind.value,
        name: trimmedName.value,
        description: description.value.trim() || null,
        is_private: isPrivate.value,
        members: [...selectedMap.value.keys()],
    };
    if (!isPrivate.value && trimmedSlug.value) {
        payload.slug = trimmedSlug.value;
    }
    if (kind.value === 'group') {
        payload.is_readonly = adminsOnly.value;
    }

    try {
        const created = await createChat(payload);
        const stored = chatStore.upsertChat(created);
        emit('created', stored);
        close();
        if (stored.slug) {
            await router.push(`/chats/${stored.slug}`);
        }
    } catch (err) {
        if (isAxiosError(err) && err.response?.status === 422) {
            const errors = (err.response.data?.errors ?? {}) as Record<string, string[]>;
            fieldErrors.value = Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]]));
            formError.value = err.response.data?.message ?? 'Please check the form.';
        } else {
            formError.value = `Could not create the ${label.value}. Please try again.`;
            console.error('Failed to create chat', err);
        }
    } finally {
        submitting.value = false;
    }
}

async function open(type: Kind = 'group'): Promise<void> {
    resetForm(type);
    if (contactStore.contacts === null) {
        void contactStore.loadContacts().catch((e) => console.error('Failed to load contacts', e));
    }
    modalOpen();
    await nextTick();
    if (!window.matchMedia('(pointer: coarse)').matches) {
        nameInput.value?.focus();
    }
}

defineExpose({ open, close, toggle, isOpen });

const inputClass =
    'w-full px-3 py-2 text-sm text-gray-900 placeholder-gray-400 bg-gray-100 border border-transparent rounded-xl outline-none dark:bg-gray-700 dark:text-gray-100 focus:bg-white dark:focus:bg-gray-800 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20';
</script>

<template>
    <Modal ref="modal" width-class="max-w-[500px] w-[90%]" height-class="h-[85%]">
        <div class="flex flex-col h-full max-h-[95vh]">
            <header class="flex items-center gap-3 px-4 pt-4 pb-2">
                <h2 class="flex-1 text-lg font-semibold text-gray-900 dark:text-gray-100">New {{ label }}</h2>
                <button type="button" aria-label="Close"
                    class="grid rounded-full cursor-pointer size-8 place-items-center hover:bg-gray-100 dark:hover:bg-gray-700"
                    @click="close">
                    <CloseIcon class="size-5 text-gray-500" />
                </button>
            </header>

            <form class="flex flex-col flex-1 min-h-0" @submit.prevent="submit">
                <div class="flex flex-col gap-4 px-4 pb-4 overflow-y-auto scrollable">
                    <div class="grid grid-cols-2 gap-2" role="group" aria-label="Chat type">
                        <button type="button" :aria-pressed="kind === 'group'"
                            class="flex items-center justify-center gap-2 px-3 py-2 text-sm font-medium transition-colors rounded-xl cursor-pointer"
                            :class="kind === 'group' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'"
                            @click="kind = 'group'">
                            <UsersIcon class="size-4" /> Group
                        </button>
                        <button type="button" :aria-pressed="kind === 'channel'"
                            class="flex items-center justify-center gap-2 px-3 py-2 text-sm font-medium transition-colors rounded-xl cursor-pointer"
                            :class="kind === 'channel' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'"
                            @click="kind = 'channel'">
                            <MegaphoneIcon class="size-4" /> Channel
                        </button>
                    </div>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ kind === 'channel' ?
                            'Channel' : 'Group' }} name</span>
                        <input ref="nameInput" v-model="name" type="text" maxlength="255" required :class="inputClass"
                            :placeholder="kind === 'channel' ? 'Channel name' : 'Group name'" />
                        <span v-if="fieldErrors.name" class="text-xs text-red-500">{{ fieldErrors.name }}</span>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Description (optional)</span>
                        <textarea v-model="description" rows="2" maxlength="512" :class="[inputClass, 'resize-none']"
                            placeholder="What is this about?"></textarea>
                    </label>

                    <fieldset class="flex flex-col gap-2">
                        <legend class="mb-1 text-xs font-medium text-gray-600 dark:text-gray-300">Who can join</legend>
                        <label class="flex items-start gap-2 text-sm text-gray-800 cursor-pointer dark:text-gray-100">
                            <input v-model="isPrivate" type="radio" :value="false" class="mt-1 accent-blue-600" />
                            <span>
                                <span class="font-medium">Public</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Anyone can find it by
                                    search or link and join.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2 text-sm text-gray-800 cursor-pointer dark:text-gray-100">
                            <input v-model="isPrivate" type="radio" :value="true" class="mt-1 accent-blue-600" />
                            <span>
                                <span class="font-medium">Private</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Only people with the invite
                                    link can join.</span>
                            </span>
                        </label>
                    </fieldset>

                    <label v-if="!isPrivate" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Public link (optional)</span>
                        <input v-model="slug" type="text" maxlength="127" autocomplete="off" autocapitalize="off"
                            :class="inputClass" placeholder="my_group_name" />
                        <span v-if="slugInvalid || fieldErrors.slug" class="text-xs text-red-500">
                            {{ fieldErrors.slug ?? 'Use 3+ letters, numbers, _ or -' }}
                        </span>
                    </label>

                    <section class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-medium text-gray-600 dark:text-gray-300">
                                Add {{ kind === 'channel' ? 'subscribers' : 'members' }} ({{ selectedMap.size }}/{{
                                MAX_MEMBERS }})
                            </h3>
                            <div class="flex gap-2 text-xs">
                                <button type="button" class="cursor-pointer px-2 py-1 rounded"
                                    :class="searchTab === 'contacts' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700'"
                                    @click="searchTab = 'contacts'">Contacts</button>
                                <button type="button" class="cursor-pointer px-2 py-1 rounded"
                                    :class="searchTab === 'global' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700'"
                                    @click="searchTab = 'global'">Search All</button>
                            </div>
                        </div>

                        <div class="relative">
                            <SearchIcon
                                class="absolute -translate-y-1/2 pointer-events-none left-3.5 top-1/2 size-4 text-gray-400" />
                            <input v-model="contactQuery" type="search"
                                :placeholder="searchTab === 'contacts' ? 'Search contacts' : 'Search by username or name'"
                                aria-label="Search users" :class="[inputClass, 'pl-10']" />
                        </div>

                        <!-- Selected chips preview -->
                        <div v-if="selectedMap.size > 0"
                            class="flex flex-wrap gap-1.5 max-h-20 overflow-y-auto p-1 bg-gray-50 dark:bg-gray-800 rounded-xl">
                            <span v-for="[username, user] in selectedMap" :key="username"
                                class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 rounded-full">
                                {{ user.name || username }}
                                <button type="button" class="hover:text-red-500 cursor-pointer"
                                    @click="toggleMember(user)">&times;</button>
                            </span>
                        </div>

                        <!-- Contacts list -->
                        <template v-if="searchTab === 'contacts'">
                            <p v-if="contactStore.isLoading" class="py-3 text-sm text-center text-gray-500">Loading
                                contacts…</p>
                            <p v-else-if="!visibleContacts.length"
                                class="py-3 text-sm text-center text-gray-500 dark:text-gray-400">No matching contacts.
                            </p>
                            <ul v-else
                                class="overflow-y-auto border border-gray-100 rounded-xl max-h-44 dark:border-gray-700 scrollable">
                                <li v-for="c in visibleContacts" :key="c.user.username">
                                    <label
                                        class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                        <input type="checkbox" class="accent-blue-600"
                                            :checked="selectedMap.has(c.user.username)"
                                            :disabled="limitReached && !selectedMap.has(c.user.username)"
                                            @change="toggleMember(c.user)" />
                                        <Avatar class="size-8 shrink-0"
                                            :src="c.user.profile_photo || '/storage/default-profile.png'" />
                                        <span class="flex flex-col min-w-0">
                                            <span class="text-sm text-gray-900 truncate dark:text-gray-100">{{ c.name ||
                                                c.user.name }}</span>
                                            <span class="text-xs text-gray-500 truncate dark:text-gray-400">@{{
                                                c.user.username }}</span>
                                        </span>
                                    </label>
                                </li>
                            </ul>
                        </template>

                        <!-- Global search list -->
                        <template v-else>
                            <p v-if="searchingGlobal" class="py-3 text-sm text-center text-gray-500">Searching users…
                            </p>
                            <p v-else-if="!contactQuery.trim()"
                                class="py-3 text-sm text-center text-gray-500 dark:text-gray-400">Type to search users
                                across the platform.</p>
                            <p v-else-if="!globalUsers.length"
                                class="py-3 text-sm text-center text-gray-500 dark:text-gray-400">No users found.</p>
                            <ul v-else
                                class="overflow-y-auto border border-gray-100 rounded-xl max-h-44 dark:border-gray-700 scrollable">
                                <li v-for="u in globalUsers" :key="u.username">
                                    <label
                                        class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                        <input type="checkbox" class="accent-blue-600"
                                            :checked="u.username ? selectedMap.has(u.username) : false"
                                            :disabled="limitReached && u.username ? !selectedMap.has(u.username) : false"
                                            @change="toggleMember(u)" />
                                        <Avatar class="size-8 shrink-0"
                                            :src="u.profile_photo || '/storage/default-profile.png'" />
                                        <span class="flex flex-col min-w-0">
                                            <span class="text-sm text-gray-900 truncate dark:text-gray-100">{{ u.name
                                                }}</span>
                                            <span class="text-xs text-gray-500 truncate dark:text-gray-400">@{{
                                                u.username }}</span>
                                        </span>
                                    </label>
                                </li>
                            </ul>
                        </template>
                    </section>

                    <p v-if="formError" role="alert" class="text-sm text-red-500">{{ formError }}</p>
                </div>

                <footer class="flex justify-end gap-2 px-4 py-3 border-t border-gray-100 dark:border-gray-700">
                    <button type="button"
                        class="px-4 py-2 text-sm font-medium text-gray-600 rounded-full cursor-pointer hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                        @click="close">Cancel</button>
                    <button type="submit" :disabled="!canSubmit"
                        class="px-5 py-2 text-sm font-medium text-white transition-colors bg-blue-600 rounded-full cursor-pointer hover:bg-blue-700 disabled:opacity-50">
                        {{ submitting ? 'Creating…' : 'Create' }}
                    </button>
                </footer>
            </form>
        </div>
    </Modal>
</template>