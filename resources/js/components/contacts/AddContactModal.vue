<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useModalControl } from '@/composables/useModalControl';
import { useFormErrors } from '@/composables/useFormErrors';
import Modal from '@/components/common/Modal.vue';
import type { Contact, User } from '@/types';
import type { Paginated } from '@/types/api';
import ContactItem from './ContactItem.vue';
import { searchUsers as apiSearchUsers, deleteContact as apiDeleteContact, addOrUpdateContact, getContacts } from '@/api';
import CloseIcon from '@/assets/svgs/Close.svg';
import SearchIcon from '@/assets/svgs/Search.svg';
import Avatar from '../chat/Avatar.vue';


const emit = defineEmits<{
    (e: 'openDirectChat', username: string): void;
    (e: 'openEditContactModal', username: string, name?: string | null): void;
}>();

const { modal, open, close, toggle, isOpen } = useModalControl();
const { errors, setFromError, clear } = useFormErrors(['username', 'name']);

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

        return (
            name.includes(search) ||
            username.includes(search)
        );
    });
});

onMounted(async () => {
    contacts.value = (await getContacts()).data;
});


const onSearch = useDebounceFn((event: Event) => {
    const target = event.target as HTMLInputElement;

    userName.value = target.value;
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
        currentPage.value = 1;
        selectedUserName.value = '';
        searchedUsers.value = [];
        saveContact(res);
        userName.value = '';
        emit('openDirectChat', res.user.username);
    } catch (err: unknown) {
        setFromError(err);
    } finally {
        isAddingContact.value = false;
    }
}

defineExpose({ open, close, toggle, isOpen, saveContact });
</script>

<template>
    <Modal ref="modal">
        <div class="flex flex-col h-full">
            <div class="flex flex-row items-start">
                <h2 class="h-12 px-6 pt-3 text-lg">Add New Contact</h2>
                <a @click="close" class="ml-auto p-4 cursor-pointer">
                    <CloseIcon class="size-6" />
                </a>
            </div>
            <div class="flex flex-col h-[calc(100%-3rem)] p-5">
                <div class="relative flex items-center">
                    <div class="absolute inset-y-0 flex items-center pointer-events-none start-0 ps-3">
                        <SearchIcon class="w-4 h-4 text-gray-500 dark:text-gray-400" />
                    </div>
                    <input type="search" placeholder="Search users ..."
                        class="block w-full h-12 p-4 text-sm text-gray-900 bg-transparent border-b-2 border-gray-300 outline-none ps-10 focus:border-blue-500 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500"
                        @input="onSearch" />
                </div>

                <div class="flex flex-col h-[calc(100%-3rem)] scrollable ">

                    <div >
                        <ContactItem v-for="contact in filteredContacts" :key="contact.user.username" :contact="contact"
                            @close-contacts-modals="close"
                            @open-edit-contact-modal="(username, name) => emit('openEditContactModal', username, name)"
                            @delete-contact="removeContact"
                        />
                    </div>
                    <!-- <template v-if="userName != ''">
                        <ContactItem v-for="contact in filteredContacts" :key="contact.user.username"
                            :contact="contact"
                            @close-contacts-modals="close"
                            @open-edit-contact-modal="(username, name) => emit('openEditContactModal', username, name)"
                            @delete-contact="removeContact"
                        />
                    </template> -->

                    <a v-for="u in searchedUsers" :key="u.username" :class="{
                        'dark:!bg-blue-700 !bg-blue-200': selectedUserName === u.username,
                    }" class="flex border-b-2 items-center px-2 py-3 align-middle [border-radius:6px_25px_6px_35px] border-gray-100 cursor-pointer dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600"
                        @click="selectedUserName = u.username!">
                        <Avatar class="w-12 h-12 mr-2" :src="u.profile_photo || '/storage/default-profile.png'"></Avatar>
                        <div class="flex flex-col w-full overflow-hidden h-12">
                            <div class="flex flex-row">
                                <h3 class="text-lg font-normal truncate">{{ u.name || '' }}</h3>
                            </div>
                            <div class="flex flex-row items-center">
                                <p class="py-1 mr-2 text-sm truncate">@{{ u.username }}</p>
                            </div>
                        </div>
                    </a>

                    <!-- <div
                        v-show="isSearching"
                        class="flex justify-center p-4 italic"
                    >
                        Searching ...
                    </div> -->

                    <template v-if="isSearching">

                        <a v-for="n in 3" :key="n"
                            class="flex border-b-1 items-center px-2 py-3 align-middle border-gray-100 dark:border-gray-700">
                            <div class="w-12 h-12 mr-2 bg-gray-100 rounded-full dark:bg-gray-600 animate-pulse"></div>
                            <div class="flex flex-col w-full h-12 overflow-hidden">
                                <div
                                    class="flex flex-row mb-3 h-4 w-48 bg-gray-100 rounded dark:bg-gray-600 animate-pulse">
                                    <h3 class="text-lg font-normal truncate"></h3>
                                </div>
                                <div
                                    class="flex flex-row h-3 w-64 items-center bg-gray-100 rounded dark:bg-gray-600 animate-pulse">
                                    <p class="py-1 mr-2 text-sm truncate"></p>
                                </div>
                            </div>
                        </a>
                    </template>



                    <div v-show="!isSearching && userName !== '' && searchedUsers.length == 0 && filteredContacts.length == 0"
                        class="flex justify-center">
                        <a class="text-gray-500 dark:text-gray-300 p-4 italic">
                            No Result For: <p class="text-sm inline">{{ userName }}</p>
                        </a>
                    </div>

                    <div v-show="!isSearching && !gotAllPages" class="flex justify-center">
                        <a class="text-blue-500 cursor-pointer hover:text-blue-300"
                            @click="currentPage++; searchUsers(userName)">
                            Load More
                        </a>
                    </div>
                </div>

                <div class="flex pt-3 pb-2 mt-auto select-none h-14">
                    <a :class="{ 'cursor-not-allowed!': selectedUserName === '' }"
                        class="p-2 text-blue-600 rounded-md cursor-pointer dark:hover:bg-gray-700 hover:bg-gray-100"
                        @click="addContact">
                        Add To Contact
                    </a>
                    <!-- <a
                        class="p-2 ml-auto text-blue-600 rounded-md cursor-pointer dark:hover:bg-gray-700 hover:bg-gray-100"
                        @click="close"
                    >
                        Close
                    </a> -->
                </div>
            </div>
        </div>
    </Modal>
</template>
