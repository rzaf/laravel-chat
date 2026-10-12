<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { onKeyStroke, useDebounceFn } from '@vueuse/core';
import { useModalControl } from '@/composables/useModalControl';
import { useFormErrors } from '@/composables/useFormErrors';
import Modal from '@/components/common/Modal.vue';
import type { Contact, User } from '@/types';
import ContactItem from './ContactItem.vue';
import { searchUsers as apiSearchUsers, deleteContact as apiDeleteContact, addOrUpdateContact, getContacts } from '@/api';
import CloseIcon from '@/assets/svgs/Close.svg';
import SearchIcon from '@/assets/svgs/Search.svg';
import CheckIcon from '@/assets/svgs/Check.svg';
import Avatar from '../chat/Avatar.vue';

const emit = defineEmits<{
    (e: 'openDirectChat', username: string): void;
    (e: 'openEditContactModal', username: string, name?: string | null): void;
}>();

const { modal, open, close, toggle, isOpen } = useModalControl();
const { errors, setFromError, clear } = useFormErrors(['username', 'name']);


onKeyStroke('Escape', () => {
    if (selectedUser.value != null){
        clearSelection();
        return;
    }
    if (userName.value != ''){
        isSearching.value = false;
        gotAllPages.value = true;
        selectedUserName.value = '';
        searchedUsers.value = [];
        userName.value = '';
        return;
    }
    if (isOpen()) close();
});


const searchedUsers = ref<User[]>([]);
const gotAllPages = ref(true);
const currentPage = ref(1);
const selectedUserName = ref('');
const userName = ref('');
const name = ref('');
const isSearching = ref(false);
const isAddingContact = ref(false);
let searchId = 0;

const contacts = ref<Contact[]>([]);

/** Insert or update a contact after it is saved elsewhere. */
function saveContact(contact: Contact): void {
    const index = contacts.value.findIndex((c) => c.user.username === contact.user.username);
    if (index === -1) {
        contacts.value.push(contact);
    } else {
        contacts.value[index].name = contact.name;
    }
}

async function removeContact(username: string): Promise<void> {
    await apiDeleteContact(username);
    contacts.value = contacts.value.filter((c) => c.user.username !== username);
}

// search in user contacts
const filteredContacts = computed(() => {
    const search = userName.value.toLowerCase().trim();

    if (!search) {
        return contacts.value;
    }

    return contacts.value.filter((contact) => {
        const name = contact.name?.toLowerCase() ?? '';
        const username = contact.user.username.toLowerCase();

        return name.includes(search) || username.includes(search);
    });
});

// the currently selected search result (for the footer preview)
const selectedUser = computed(() => searchedUsers.value.find((u) => u.username === selectedUserName.value) ?? null);

const hasNoResults = computed(
    () => !isSearching.value && userName.value !== '' && searchedUsers.value.length === 0 && filteredContacts.value.length === 0,
);
const hasNoContactsYet = computed(() => userName.value === '' && contacts.value.length === 0);

onMounted(async () => {
    contacts.value = (await getContacts()).data;
});

const onSearch = useDebounceFn((event: Event) => {
    const target = event.target as HTMLInputElement;

    currentPage.value = 1;
    selectedUserName.value = '';
    searchedUsers.value = [];
    searchUsers(userName.value);
}, 350);

async function searchUsers(searchTerm: string) {
    const id = ++searchId;
    if (searchTerm === '') {
        gotAllPages.value = true; // disabling show more button
        isSearching.value = false;
        return;
    }

    isSearching.value = true;

    try {
        const res = await apiSearchUsers(searchTerm, currentPage.value);

        if (id !== searchId) return;

        searchedUsers.value.push(...res.data);
        gotAllPages.value = res.links.next === null;
    } finally {
        if (id === searchId) {
            isSearching.value = false;
        }
    }
}

async function addContact(): Promise<void> {
    if (selectedUserName.value === '') {
        errors.username = 'username is required';
        return;
    }

    isAddingContact.value = true;
    clear();

    try {
        const res = await addOrUpdateContact(selectedUserName.value, name.value);
        userName.value = '';
        name.value = '';
        currentPage.value = 1;
        selectedUserName.value = '';
        searchedUsers.value = [];
        saveContact(res);
        emit('openDirectChat', res.user.username);
    } catch (err: unknown) {
        setFromError(err);
    } finally {
        isAddingContact.value = false;
    }
}

function clearSelection(): void {
    selectedUserName.value = '';
    name.value = '';
    clear();
}

defineExpose({ open, close, toggle, isOpen, saveContact });
</script>

<template>
    <Modal ref="modal">
        <div class="flex flex-col h-full">
            <!-- Header -->
            <div class="flex items-center justify-between px-5 pt-4 pb-2">
                <div>
                    <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100">Contacts</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ contacts.length }} {{ contacts.length === 1 ? 'contact' : 'contacts' }}
                    </p>
                </div>
                <button type="button" aria-label="Close"
                    class="grid transition-colors rounded-full size-10 place-items-center hover:bg-gray-100 dark:hover:bg-gray-700"
                    @click="close">
                    <CloseIcon class="size-6 text-gray-500 dark:text-gray-300" />
                </button>
            </div>

            <!-- Search -->
            <div class="px-5 pb-3">
                <div class="relative">
                    <SearchIcon
                        class="absolute -translate-y-1/2 pointer-events-none left-3.5 top-1/2 size-4 text-gray-400" />
                    <input v-model="userName" type="search" placeholder="Search contacts or find new people"
                        aria-label="Search contacts or find new people"
                        class="w-full h-11 pl-10 pr-4 text-sm text-gray-900 placeholder-gray-400 transition bg-gray-100 border border-transparent outline-none rounded-full dark:bg-gray-700 dark:text-white focus:bg-white dark:focus:bg-gray-800 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                        @input="onSearch" />
                </div>
            </div>

            <!-- Scrollable content -->
            <div class="flex flex-col flex-1 min-h-0 px-3 pb-2 overflow-y-auto scrollable">
                <!-- Your contacts -->
                <section v-if="filteredContacts.length > 0">
                    <h3
                        class="px-2 pt-1 pb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                        {{ userName ? 'In your contacts' : 'Your contacts' }}
                    </h3>
                    <ContactItem v-for="contact in filteredContacts" :key="contact.user.username" :contact="contact"
                        @close-contacts-modals="close"
                        @open-edit-contact-modal="(username, name) => emit('openEditContactModal', username, name)"
                        @delete-contact="removeContact" />
                </section>

                <!-- Empty: no contacts yet -->
                <div v-if="hasNoContactsYet" class="flex flex-col items-center gap-1 px-6 py-12 text-center">
                    <p class="font-medium text-gray-800 dark:text-gray-100">No contacts yet</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Search for a name or username above to add
                        someone.</p>
                </div>

                <!-- Search results (people you can add) -->
                <section v-if="searchedUsers.length > 0 || isSearching" class="mt-2">
                    <h3
                        class="px-2 pt-1 pb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                        People you can add
                    </h3>

                    <button v-for="u in searchedUsers" :key="u.username" type="button"
                        :aria-pressed="selectedUserName === u.username"
                        class="flex items-center w-full gap-3 px-2 py-2.5 text-left transition-colors rounded-xl ring-2 ring-transparent outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                        :class="selectedUserName === u.username
                            ? 'bg-blue-100 ring-blue-500 dark:bg-blue-500/15'
                            : 'hover:bg-gray-100 dark:hover:bg-gray-700'
                            " @click="selectedUserName = u.username!">
                        <Avatar class="size-12 shrink-0" :src="u.profile_photo || '/storage/default-profile.png'" />
                        <span class="flex flex-col flex-1 min-w-0">
                            <span class="text-base font-medium text-gray-900 truncate dark:text-gray-100">
                                {{ u.name || '' }}
                            </span>
                            <span class="text-sm text-gray-500 truncate dark:text-gray-400">@{{ u.username }}</span>
                        </span>
                        <CheckIcon v-if="selectedUserName === u.username" class="text-blue-600 size-5 shrink-0 dark:text-blue-400" />
                    </button>

                    <!-- Skeletons while searching -->
                    <template v-if="isSearching">
                        <div v-for="n in 3" :key="n" class="flex items-center gap-3 px-2 py-2.5" aria-hidden="true">
                            <div class="bg-gray-100 rounded-full size-12 shrink-0 dark:bg-gray-700 animate-pulse"></div>
                            <div class="flex-1 space-y-2">
                                <div class="w-2/5 h-4 bg-gray-100 rounded dark:bg-gray-700 animate-pulse"></div>
                                <div class="w-3/5 h-3 bg-gray-100 rounded dark:bg-gray-700 animate-pulse"></div>
                            </div>
                        </div>
                    </template>
                </section>

                <!-- No results -->
                <div v-if="hasNoResults" class="px-6 py-12 text-center">
                    <p class="font-medium text-gray-800 dark:text-gray-100">No results</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Nothing found for “{{ userName }}”. Check the spelling or try a username.
                    </p>
                </div>

                <!-- Load more -->
                <div v-show="!isSearching && !gotAllPages" class="flex justify-center py-3">
                    <button type="button"
                        class="px-4 py-1.5 text-sm font-medium text-blue-600 rounded-full hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-gray-700"
                        @click="currentPage++; searchUsers(userName)">
                        Load more
                    </button>
                </div>
            </div>

            <!-- Add footer: appears once someone is selected -->
            <Transition enter-from-class="opacity-0 translate-y-2" enter-active-class="transition duration-150"
                leave-to-class="opacity-0 translate-y-2" leave-active-class="transition duration-100">
                <form v-if="selectedUserName !== ''"
                    class="px-5 pt-3 pb-4 border-t border-gray-100 dark:border-gray-700" @submit.prevent="addContact">
                    <div class="flex items-center gap-2 mb-3">
                        <Avatar class="size-8 shrink-0"
                            :src="selectedUser?.profile_photo || '/storage/default-profile.png'" />
                        <p class="flex-1 min-w-0 text-sm text-gray-700 truncate dark:text-gray-200">
                            Adding <span class="font-medium">@{{ selectedUserName }}</span>
                        </p>
                        <button type="button" aria-label="Deselect user"
                            class="grid rounded-full size-7 place-items-center hover:bg-gray-100 dark:hover:bg-gray-700"
                            @click="clearSelection">
                            <CloseIcon class="size-5 text-gray-500 dark:text-gray-300" />
                        </button>
                    </div>

                    <div class="flex gap-2">
                        <input v-model="name" type="text" placeholder="Contact name (optional)"
                            aria-label="Contact name"
                            class="flex-1 min-w-0 px-4 text-sm bg-transparent border border-gray-300 rounded-full outline-none h-11 dark:border-gray-600 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20" />
                        <button type="submit" :disabled="isAddingContact"
                            class="px-5 text-sm font-medium text-white transition-colors bg-blue-600 rounded-full h-11 hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed">
                            {{ isAddingContact ? 'Adding…' : 'Add contact' }}
                        </button>
                    </div>

                    <p v-if="errors.username" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ errors.username }}
                    </p>
                    <p v-if="errors.name" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ errors.name }}</p>
                </form>
            </Transition>
        </div>
    </Modal>
</template>