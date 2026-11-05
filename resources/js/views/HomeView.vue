<!-- src/views/HomeView.vue -->
<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import useAuthStore from '@/stores/auth';
import useChatStore from '@/stores/chat';
import { useChatChannel } from '@/realtime/useChatChannel';
import { disconnectEcho } from '@/realtime/echo';
import ChatList from '@/components/chat/ChatList.vue';
import ChatWindow from '@/components/chat/ChatWindow.vue';
import GlobalAudioPlayer from '@/components/chat/GlobalAudioPlayer.vue';
import AppSidebar from '@/components/layout/AppSidebar.vue';
import ContactsModal from '@/components/contacts/ContactsModal.vue';
import AddContactModal from '@/components/contacts/AddContactModal.vue';
import EditContactModal from '@/components/contacts/EditContactModal.vue';
import SettingModal from '@/components/modals/SettingModal.vue';
import ProfileModal from '@/components/modals/ProfileModal.vue';
import CreateChatModal from '@/components/modals/CreateChatModal.vue';
import AddChatMembersModal from '@/components/modals/AddChatMembersModal.vue';
import Modal from '@/components/common/Modal.vue';

const route = useRoute();
const router = useRouter();

const auth = useAuthStore();
const chat = useChatStore();

const showSidebar = ref(false);
const pageNotFound = ref(route.name === 'notFound');

const addContactModalRef = ref<InstanceType<typeof AddContactModal> | null>(null);
const editContactModalRef = ref<InstanceType<typeof EditContactModal> | null>(null);
const profileModalRef = ref<InstanceType<typeof Modal> | null>(null);
const settingModalRef = ref<InstanceType<typeof Modal> | null>(null);
const createChatModalRef = ref<InstanceType<typeof CreateChatModal> | null>(null);
const addMembersModalRef = ref<InstanceType<typeof AddChatMembersModal> | null>(null);

onMounted(() => {
    if (auth.user?.id) {
        useChatChannel(auth.user.id);
    }
    chat.loadChatList();
})

watch(
    () => route.fullPath,
    () => {
        pageNotFound.value = route.name === 'notFound';
        if (route.name === 'chat') {
            const slug = route.params.slug as string;
            chat.selectChatBySlug(slug);
        } else {
            chat.clearCurrentChat();
        }
    },
    { immediate: true },
);

async function handleLogout() {
    disconnectEcho();
    auth.logout();
    const failure = await router.replace({ name: 'login' });
    console.log('logout nav result:', failure, router.currentRoute.value.fullPath);
}
router.onError((e) => console.error('router error', e));
</script>

<template>
    <div class="relative flex w-full h-full overflow-hidden">
        <!-- Sidebar is positioned outside the workspace column -->
        <AppSidebar v-model:open="showSidebar" @open-contacts="" @open-add-contact="addContactModalRef?.open()"
            @open-setting="settingModalRef?.open()" @open-create-chat="(type) => createChatModalRef?.open(type)"
            @logout="handleLogout" @open-profile="(slug) => {
                console.log(`openning chat CURRENT USER : ${slug}`);
                profileModalRef?.open(slug, null);
            }" />

        <!-- Main Workspace Column (Chat List, Chat Window, and Global Audio Player stacked vertically) -->
        <div class="flex flex-col flex-1 h-full min-w-0 relative bg-gray-100 dark:bg-gray-900">

            <!-- Upper workspace area (ChatList and Empty/ChatWindow) -->
            <div class="relative flex flex-1 min-h-0 w-full">
                <!-- Chat List remains visible on mobile; on mobile when a chat is selected, we slide the chat window over/across -->
                <ChatList class="w-full lg:w-96 shrink-0 h-full" @toggle-menu="showSidebar = !showSidebar" />

                <div class="flex flex-col items-center justify-center bg-gray-100 dark:bg-gray-900 w-full h-full">
                    <h2 class="text-lg italic font-light">
                        {{ pageNotFound ? 'page not found !!!' : 'no chat is selected' }}
                    </h2>
                </div>

                <Transition name="slide-chat">
                    <div v-if="chat.currentChat != null"
                        class="absolute inset-0 lg:left-96 lg:w-[calc(100%-24rem)] w-full h-full bg-gray-100 dark:bg-gray-900 z-20">
                        <ChatWindow :chat="chat.currentChat" @open-chat-profile="(chatItem) => {
                            console.log(`openning chat profile for : ${chatItem.slug}`, chatItem);
                            profileModalRef?.open(chatItem.slug, chatItem);
                        }" />
                    </div>
                </Transition>
            </div>

            <!-- Global Audio Player sits at the bottom of the workspace column -->
            <GlobalAudioPlayer />
        </div>
    </div>

    <EditContactModal ref="editContactModalRef" />

    <AddContactModal ref="addContactModalRef" @open-direct-chat="(username) => {
        addContactModalRef?.close();
        router.push(`/chats/${username}`)
    }" @open-edit-contact-modal="(username, name) => editContactModalRef?.open(username, name)" />

    <ProfileModal ref="profileModalRef"
        @open-edit-contact-modal="(username, name) => editContactModalRef?.open(username, name)"
        @open-add-members="(chatItem) => addMembersModalRef?.open(chatItem)" />

    <CreateChatModal ref="createChatModalRef" />

    <AddChatMembersModal ref="addMembersModalRef" @added="profileModalRef?.reloadMembers()" />

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