<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { getContacts, deleteContact as apiDeleteContact } from '@/api';
import { useModalControl } from '@/composables/useModalControl';
import ContactItem from '@/components/contacts/ContactItem.vue';
import Modal from '@/components/common/Modal.vue';
import type { Contact } from '@/types';

const emit = defineEmits<{
    (e: 'openEditContactModal', username: string, name?: string | null): void;
}>();

const contacts = ref<Contact[]>([]);
const { modal, open, close, toggle, isOpen } = useModalControl();

onMounted(async () => {
    contacts.value = await getContacts();
});

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

defineExpose({ open, close, toggle, isOpen, saveContact });
</script>

<template>
    <Modal ref="modal">
        <div class="flex flex-col h-full">
            <h2 class="h-12 px-6 pt-3 text-lg">Contacts</h2>

            <div class="flex flex-col mx-5 h-[calc(100%-3rem)]">
                <div class="relative flex items-center">
                    <div class="absolute inset-y-0 flex items-center pointer-events-none start-0 ps-3">
                        <svg
                            class="w-4 h-4 text-gray-500 dark:text-gray-400"
                            fill="none"
                            viewBox="0 0 20 20"
                        >
                            <path
                                stroke="currentColor"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"
                            />
                        </svg>
                    </div>
                    <input
                        type="search"
                        placeholder="Search contacts ..."
                        class="block w-full h-12 p-4 text-sm text-gray-900 bg-transparent border-b-2 border-gray-300 outline-none ps-10 focus:border-blue-500 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:border-blue-500"
                    />
                </div>

                <div class="flex flex-col h-[calc(100%-3rem)] scrollable">
                    <ContactItem
                        v-for="contact in contacts"
                        :key="contact.user.username"
                        :contact="contact"
                        @close-contacts-modals="close"
                        @open-edit-contact-modal="(username, name) => emit('openEditContactModal', username, name)"
                        @delete-contact="removeContact"
                    />
                </div>
            </div>
        </div>
    </Modal>
</template>
