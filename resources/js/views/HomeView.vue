<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import useAuthStore from '@/stores/auth';
import useChatStore from '@/stores/chat';
import { useChatChannel } from '@/realtime/useChatChannel';
import { disconnectEcho } from '@/realtime/echo';
import ChatList from '@/components/chat/ChatList.vue';
import ChatWindow from '@/components/chat/ChatWindow.vue';
import AppSidebar from '@/components/layout/AppSidebar.vue';
import ContactsModal from '@/components/contacts/ContactsModal.vue';
import AddContactModal from '@/components/contacts/AddContactModal.vue';
import EditContactModal from '@/components/contacts/EditContactModal.vue';
import SettingModal from '@/components/modals/SettingModal.vue';
import ProfileModal from '@/components/modals/ProfileModal.vue';
import Modal from '@/components/common/Modal.vue';

const route = useRoute();
const router = useRouter();

const auth = useAuthStore();
const chat = useChatStore();


if (auth.user?.id) {
    useChatChannel(auth.user.id);
}

const showSidebar = ref(false);
const pageNotFound = ref(route.name === 'notFound');

const addContactModalRef = ref<InstanceType<typeof AddContactModal> | null>(null);
const editContactModalRef = ref<InstanceType<typeof EditContactModal> | null>(null);
const profileModalRef = ref<InstanceType<typeof Modal> | null>(null);
const settingModalRef = ref<InstanceType<typeof Modal> | null>(null);

chat.loadChatList();

watch(
    () => route.fullPath,
    () => {
        pageNotFound.value = route.name === 'notFound';
        if (route.name === 'privateChats') {
            const slug = route.params.username as string;
            chat.selectChatBySlug(slug);
        } else {
            chat.clearCurrentChat();
        }
    },
    { immediate: true },
);

function handleLogout(): void {
    disconnectEcho();
    auth.logout();
    // window.location.reload();
    window.location.href = '/sign-in';
}
</script>

<template>
    <div class="relative flex w-full h-full">
        <AppSidebar
            v-model:open="showSidebar"
            @open-contacts=""
            @open-add-contact="addContactModalRef?.open()"
            @open-setting="settingModalRef?.open()"
            @logout="handleLogout"
        />

        <ChatList
            :class="{ 'max-lg:hidden': chat.currentChat != null }"
            @toggle-menu="showSidebar = !showSidebar"
        />

        <div class="w-full h-full relative bg-gray-100 dark:bg-gray-900" :class="{ 'max-lg:hidden': chat.currentChat == null }">
            <div v-if="chat.currentChat == null" class="flex flex-col items-center justify-center w-full h-full">
                <h2 class="text-lg italic font-light">
                    {{ pageNotFound ? 'page not found !!!' : 'no chat is selected' }}
                </h2>
            </div>
            <ChatWindow v-else :chat="chat.currentChat" 
                @open-chat-profile="(chat) => {
                    console.log(`openning chat profile for : ${chat.slug}`, chat);
                    profileModalRef?.open(chat.slug, chat);
                }"
            />
        </div>
    </div>

    <!-- <ContactsModal
        ref="contactsModalRef"
        @open-edit-contact-modal="(username, name) => editContactModalRef?.open(username, name)"
    /> -->

    <AddContactModal
        ref="addContactModalRef"
        @open-direct-chat="(username) => {
            addContactModalRef?.close();
            router.push(`/users/${username}`)
        }"
        @open-edit-contact-modal="(username, name) => editContactModalRef?.open(username, name)"
    />

    <EditContactModal
        ref="editContactModalRef"
        @open-contacts="(c) => {
            editContactModalRef?.close();
            addContactModalRef?.open();
            addContactModalRef?.saveContact(c);
        }"
    />

    <ProfileModal
        ref="profileModalRef"
    />

    <Modal ref="settingModalRef">
        <SettingModal />
    </Modal>
</template>
