<script setup lang="ts">
import { ref } from 'vue';
import { addOrUpdateContact } from '@/api';
import { useModalControl } from '@/composables/useModalControl';
import { useFormErrors } from '@/composables/useFormErrors';
import Modal from '@/components/common/Modal.vue';
import InputComponent from '@/components/common/Input.vue';
import type { Contact } from '@/types';

const emit = defineEmits<{
    (e: 'openContacts', contact: Contact): void;
}>();

const { modal, open: modalOpen, close, toggle, isOpen } = useModalControl();
const { errors, formError, setFromError, clear } = useFormErrors(['username', 'name']);

const userName = ref('');
const name = ref('');
const isSending = ref(false);

function open(u: string, n?: string | null): void {
    userName.value = u;
    name.value = n ?? '';
    clear();
    modalOpen();
}

async function updateContact(): Promise<void> {
    if (userName.value === '') {
        errors.username = 'username is required';
        return;
    }

    isSending.value = true;
    clear();

    try {
        const res = await addOrUpdateContact(userName.value, name.value);
        emit('openContacts', res);
    } catch (err: unknown) {
        setFromError(err);
    } finally {
        isSending.value = false;
    }
}

defineExpose({ open, close, toggle, isOpen });
</script>

<template>
    <Modal ref="modal" width-class="lg:w-80 w-[90%]">
        <div class="flex flex-col h-full">
            <h2 class="h-12 px-6 pt-3 text-lg">Update Contact</h2>
            <div class="flex flex-col h-[calc(100%-3rem)] p-5">
                <InputComponent
                    :model-value="userName"
                    name="username"
                    label="Username"
                    :is-disabled="true"
                    :error-str="errors.username"
                />

                <InputComponent v-model="name" name="name" label="Name" :error-str="errors.name" />

                <p v-show="formError" class="mb-4 text-xs text-red-600 dark:text-red-500">{{ formError }}</p>

                <div class="flex pt-3 pb-2 mt-auto select-none h-14">
                    <a
                        class="p-2 text-blue-600 rounded-md cursor-pointer dark:hover:bg-gray-700 hover:bg-gray-100"
                        @click="updateContact"
                    >
                        Update Contact
                    </a>
                    <a
                        class="p-2 ml-auto text-blue-600 rounded-md cursor-pointer dark:hover:bg-gray-700 hover:bg-gray-100"
                        @click="close"
                    >
                        Close
                    </a>
                </div>
            </div>
        </div>
    </Modal>
</template>
