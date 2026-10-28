import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { getContacts, deleteContact as apiDeleteContact } from '@/api';
import type { Contact } from '@/types';

export default defineStore('contact', () => {
    const contacts = ref<Contact[] | null>(null);
    const contactsLoadPromise = ref<Promise<void> | null>(null);

    const isLoading = computed(() => contacts.value === null);

    async function loadContacts(): Promise<void> {
        if (contactsLoadPromise.value) {
            return contactsLoadPromise.value;
        }

        contactsLoadPromise.value = (async () => {
            try {
                const res = await getContacts();
                contacts.value = res.data ?? res;
            } finally {
                contactsLoadPromise.value = null;
            }
        })();

        return contactsLoadPromise.value;
    }

    function saveContact(contact: Contact): void {
        if (!contacts.value) {
            contacts.value = [];
        }
        const index = contacts.value.findIndex((c) => c.user.username === contact.user.username);
        if (index === -1) {
            contacts.value.push(contact);
        } else {
            contacts.value[index].name = contact.name;
        }
    }

    async function removeContact(username: string): Promise<void> {
        await apiDeleteContact(username);
        if (contacts.value) {
            contacts.value = contacts.value.filter((c) => c.user.username !== username);
        }
    }

    function clear(): void {
        contacts.value = null;
    }

    return {
        contacts,
        isLoading,
        loadContacts,
        saveContact,
        removeContact,
        clear,
    };
});