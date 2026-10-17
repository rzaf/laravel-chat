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
    window.location.href = '/sign-in';
}
</script>

<template>
    <div class="relative flex w-full h-full overflow-hidden">
        <AppSidebar v-model:open="showSidebar" @open-contacts="" @open-add-contact="addContactModalRef?.open()"
            @open-setting="settingModalRef?.open()" @logout="handleLogout" />

        <!-- Chat List remains visible on mobile; on mobile when a chat is selected, we slide the chat window over/across -->
        <ChatList class="w-full xl:w-96 shrink-0 h-full" @toggle-menu="showSidebar = !showSidebar" />

        <div class="flex flex-col items-center justify-center bg-gray-100 dark:bg-gray-900  w-full h-full">
            <h2 class="text-lg italic font-light">
                {{ pageNotFound ? 'page not found !!!' : 'no chat is selected' }}
            </h2>
        </div>

        <Transition name="slide-chat">
            <div v-if="chat.currentChat != null"
                class="absolute inset-0 xl:left-96 xl:w-[calc(100%-24rem)] w-full h-full bg-gray-100 dark:bg-gray-900 z-20">
                <ChatWindow :chat="chat.currentChat" @open-chat-profile="(chatItem) => {
                    console.log(`openning chat profile for : ${chatItem.slug}`, chatItem);
                    profileModalRef?.open(chatItem.slug, chatItem);
                }" />
            </div>
        </Transition>
    </div>

    <EditContactModal ref="editContactModalRef" @open-contacts="(c) => {
        editContactModalRef?.close();
        addContactModalRef?.open();
        addContactModalRef?.saveContact(c);
    }" />
    
    <AddContactModal ref="addContactModalRef" @open-direct-chat="(username) => {
        addContactModalRef?.close();
        router.push(`/users/${username}`)
    }" @open-edit-contact-modal="(username, name) => editContactModalRef?.open(username, name)" />


    <ProfileModal ref="profileModalRef" />

    <Modal ref="settingModalRef">
        <SettingModal />
    </Modal>
</template>

<style scoped>
/* Slide from right to left when entering / opening chat */
.slide-chat-enter-active {
    transition: transform 0.250s ease-in;
}

.slide-chat-leave-active {
    transition: transform 0.300s ease-out;
}

.slide-chat-enter-from {
    transform: translateX(100%);
}

/* Slide from left to right when leaving / closing chat back to null */
.slide-chat-leave-to {
    transform: translateX(100%);
}
</style>