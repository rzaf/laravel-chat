<script setup lang="ts">
import { ref, computed } from 'vue';
import { addOrUpdateContact } from '@/api';
import { useModalControl } from '@/composables/useModalControl';
import { useFormErrors } from '@/composables/useFormErrors';
import Modal from '@/components/common/Modal.vue';
import InputComponent from '@/components/common/Input.vue';
import type { Contact } from '@/types';
import useContactStore from '@/stores/contact';

const emit = defineEmits<{
    (e: 'openContacts', contact: Contact): void;
}>();

const { modal, open: modalOpen, close, toggle, isOpen } = useModalControl();
const { errors, formError, setFromError, clear } = useFormErrors(['username', 'name']);
const contactStore = useContactStore();

const userName = ref('');
const name = ref('');
const isEditing = ref(false);
const isSending = ref(false);

const modalTitle = computed(() => (isEditing.value ? 'Edit Contact' : 'Add Contact'));
const submitButtonText = computed(() => (isSending.value ? 'Saving…' : isEditing.value ? 'Save changes' : 'Add contact'));

function open(u: string, n?: string | null, editing: boolean = true): void {
    userName.value = u;
    name.value = n ?? '';
    isEditing.value = editing;
    clear();
    modalOpen();
}

async function saveContact(): Promise<void> {
    if (userName.value === '') {
        errors.username = 'username is required';
        return;
    }

    isSending.value = true;
    clear();

    try {
        const res = await addOrUpdateContact(userName.value, name.value);
        contactStore.saveContact(res);
        close();
    } catch (err: unknown) {
        setFromError(err);
    } finally {
        isSending.value = false;
    }
}

defineExpose({ open, close, toggle, isOpen });
</script>

<template>
    <Modal ref="modal" width-class="max-w-[400px] w-[90%]">
        <div class="flex flex-col h-full">
            <h2 class="px-6 pt-5 pb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ modalTitle }}
            </h2>
            <div class="flex flex-col p-6 gap-4 h-full">
                <InputComponent :model-value="userName" name="username" label="Username" :is-disabled="true"
                    :error-str="errors.username" />

                <InputComponent v-model="name" name="name" label="Name (optional)" :error-str="errors.name" />

                <p v-show="formError" class="text-xs text-red-600 dark:text-red-400">{{ formError }}</p>

                <div
                    class="flex items-center justify-end gap-3 pt-4 mt-auto  dark:border-gray-700">
                    <button type="button"
                        class="px-4 py-2 text-sm font-medium text-gray-700 transition-colors rounded-full hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                        @click="close">
                        Cancel
                    </button>
                    <button type="button" :disabled="isSending"
                        class="px-5 py-2 text-sm font-medium text-white transition-colors bg-blue-600 rounded-full hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed"
                        @click="saveContact">
                        {{ submitButtonText }}
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>