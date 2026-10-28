<script setup lang="ts">
import { computed, ref } from 'vue';
import { useModalControl } from '@/composables/useModalControl';
import { useConfirm } from '@/composables/useConfirm';
import useChatStore from '@/stores/chat';
import useContactStore from '@/stores/contact';
import Modal from '@/components/common/Modal.vue';
import CloseIcon from '@/assets/svgs/Close.svg';
import MailIcon from '@/assets/svgs/Mail.svg';
import TextSearchIcon from '@/assets/svgs/TextSearch.svg';
import CalenderRangeIcon from '@/assets/svgs/CalenderRange.svg';
import CalenderPlusIcon from '@/assets/svgs/CalenderPlus.svg';
import BellIcon from '@/assets/svgs/Bell.svg';
import BellSlashIcon from '@/assets/svgs/BellSlash.svg';
import BookUserIcon from '@/assets/svgs/BookUser.svg';
import UsersIcon from '@/assets/svgs/Users.svg';
import UserPlusIcon from '@/assets/svgs/UserPlus.svg';
import UserMinusIcon from '@/assets/svgs/UserMinus.svg';
import UserIcon from '@/assets/svgs/User.svg';
import Avatar from '../chat/Avatar.vue';
import type { Chat, User } from '@/types';
import { getChatBySlug, setChatMute, clearChatHistory, deleteChat } from '@/api/index.ts';
import ProfileImageGallery from '@/components/modals/ProfileImageGallery.vue';
import router from '@/router';

const emit = defineEmits<{
    (e: 'openEditContactModal', username: string, name?: string | null, editing?: boolean): void;
}>();

const { modal, open: modalOpen, close, toggle, isOpen } = useModalControl();
const chatStore = useChatStore();
const contactStore = useContactStore();
const { confirm } = useConfirm();

const chatSlug = ref<string | null>(null);
const chat = ref<Chat | null>(null);
const user = ref<User | null>(null);
const isLoading = ref<boolean>(true);
const profileImageGallery = ref<InstanceType<typeof ProfileImageGallery> | null>(null);

const isContact = computed(() => {
    const username = user.value?.username || (chat.value?.type === 'direct' ? chat.value?.slug : null);
    if (!username) return false;
    return contactStore.contacts?.some((c) => c.user.username === username);
});

const contactItem = computed(() => {
    const username = user.value?.username || (chat.value?.type === 'direct' ? chat.value?.slug : null);
    if (!username) return null;
    return contactStore.contacts?.find((c) => c.user.username === username) || null;
});

async function LoadChat() {
    if (!chatSlug.value) return;

    isLoading.value = true;
    try {
        const res = await getChatBySlug(chatSlug.value);
        chat.value = res.chat;
        user.value = res.user;
        if (chat.value == null ) {
            chat.value = <Chat>{
                slug: user.value.username,
                type: 'direct',
            };
        }
    } finally {
        isLoading.value = false;
    }
}

function open(slug: string, c: Chat | null): void {
    if (!slug) return;

    if (contactStore.contacts === null) {
        contactStore.loadContacts();
    }

    profileImageGallery.value?.destroyPhotoSwipe();
    chat.value = c;
    user.value = null;
    chatSlug.value = slug;

    profileImageGallery.value?.resetPhotos(slug);
    if (c == null || c.type !== 'group') {
        LoadChat();
        profileImageGallery.value?.startFirstPage(slug);
    }
    modalOpen();
}

function handleAvatarClick() {
    profileImageGallery.value?.openForProfile(
        chatSlug.value || chat.value?.slug || '',
        chat.value?.profile_photo,
    );
}

function formatDate(date?: string | null): string {
    if (!date) return 'Unknown';
    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    }).format(new Date(date));
}

function formatLastMessageDate(date?: string | null): string {
    if (!date) return 'No messages yet';
    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(date));
}

function handleSearch(): void {
    console.log('Search conversation:', chat.value?.id);
}

function handleAddToContact(): void {
    const username = user.value?.username || chat.value?.slug;
    if (!username) return;
    emit('openEditContactModal', username, user.value?.name || chat.value?.name, false);
}

function handleEditContact(): void {
    const username = user.value?.username || chat.value?.slug;
    if (!username) return;
    emit('openEditContactModal', username, contactItem.value?.name || user.value?.name, true);
}

async function handleRemoveFromContact(): void {
    const username = user.value?.username || chat.value?.slug;
    if (!username) return;

    const confirmed = await confirm({
        title: 'Delete Contact',
        message: `Are you sure you want to delete ${username} from your contacts?`,
        confirmText: 'Delete',
        variant: 'danger',
    });

    if (!confirmed) return;

    try {
        await contactStore.removeContact(username);
    } catch (error) {
        console.error('Failed to remove contact', error);
    }
}

async function handleToggleMute(): void {
    console.log('profile handleToggleMute', chat.value);
    if (!chat.value?.id) return;
    console.log('profile handleToggleMute');
    try {
        const newMuted = !chat.value.muted;
        const duration = newMuted ? 'forever' : null;
        const res = await setChatMute(chat.value.id, duration);
        chat.value.muted = res.muted;
        chat.value.muted_until = res.muted_until;

        const storeChat = chatStore.chatList?.find((c) => c.id === chat.value?.id);
        if (storeChat) {
            storeChat.muted = res.muted;
            storeChat.muted_until = res.muted_until;
        }
    } catch (error) {
        console.error('Failed to toggle mute status', error);
    }
}

async function handleClearChat(): void {
    if (!chat.value?.id) return;
    const res = await confirm({
        title: 'Clear Chat History',
        message: `Are you sure you want to clear the history for ${chat.value.name || chat.value.slug}?`,
        confirmText: 'Clear',
        variant: 'warning',
        checkboxLabel: chat.value.type === 'direct' ? undefined : 'Delete messages for everyone',
    });

    const confirmed = typeof res === 'boolean' ? res : res.confirmed;
    const deleteForEveryone = typeof res === 'object' ? res.checkbox : false;

    if (!confirmed) return;

    try {
        await clearChatHistory(chat.value.id, deleteForEveryone);
    } catch (error) {
        console.error('Failed to clear history', error);
    }
}

async function handleDeleteChat(): void {
    if (!chat.value?.id) return;
    const res = await confirm({
        title: 'Delete Chat',
        message: `Are you sure you want to delete the chat with ${chat.value.name || chat.value.slug}?`,
        confirmText: 'Delete',
        variant: 'danger',
        checkboxLabel: 'Delete for everyone',
    });

    const confirmed = typeof res === 'boolean' ? res : res.confirmed;
    const deleteForEveryone = typeof res === 'object' ? res.checkbox : false;

    if (!confirmed) return;

    try {
        const chatId = chat.value.id;
        await deleteChat(chatId, deleteForEveryone);
        chatStore.removeChat(chatId);
        if (chatStore.currentChat?.id == chatId) {
            router.push('/');
        }
        close();
    } catch (error) {
        console.error('Failed to delete chat', error);
    }
}

function handleLeaveChat(): void {
    console.log('Leave group:', chat.value?.id);
}

function handleBlockUser(): void {
    console.log('Block user:', chat.value?.direct_user_id);
}

defineExpose({
    open,
    close,
    toggle,
    isOpen,
});
</script>

<template>
    <Modal ref="modal" width-class="max-w-[500px] w-[90%]" height-class="h-[70%]">
        <ProfileImageGallery ref="profileImageGallery" />
        <div class="relative flex flex-col h-full max-h-[95vh]">
            <!-- Close -->
            <button type="button" @click="close"
                class="absolute top-2 left-2 z-10 p-3 rounded-full cursor-pointer text-gray-400 dark:hover:text-gray-300 hover:text-gray-500 hover:bg-black/5 dark:hover:bg-white/5 transition-colors duration-150"
                aria-label="Close">
                <CloseIcon class="size-6" />
            </button>

            <!-- Header -->
            <div class="flex flex-col items-center px-6 pt-8 pb-5 border-b border-white/10">
                <button type="button" @click="handleAvatarClick"
                    class="block rounded-full overflow-hidden focus:outline-none"
                    :class="{ 'cursor-pointer': chat?.profile_photo != null }" aria-label="View profile photos">
                    <Avatar class="w-20 h-20 pointer-events-none"
                        :src="chat?.profile_photo || '/storage/default-profile.png'" />
                </button>

                <h2 class="mt-3 text-lg font-semibold text-gray-800 dark:text-gray-200">
                    {{ chat?.name || user?.name || '...' }}
                </h2>

                <template v-if="chat?.type === 'direct'">
                    <p class="mt-1 text-sm text-gray-500">
                        @{{ chatSlug }}
                    </p>
                </template>

                <template v-else-if="chat?.type === 'group'">
                    <p class="mt-1 text-sm text-gray-500">
                        {{ chat.slug ? `@${chat.slug}` : 'Group Chat' }}
                        <span v-if="chat?.members_count">
                            <span>.</span>{{ chat.members_count }}
                            {{ chat.members_count === 1 ? 'member' : 'members' }}
                        </span>
                    </p>
                </template>
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto scrollable px-5 py-4">
                <!-- ================= DIRECT CHAT ================= -->
                <template v-if="chat?.type === 'direct'">
                    <section>
                        <h3 class="px-1 mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">
                            Information
                        </h3>
                        <div
                            class="overflow-hidden rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                            <div class="flex items-center gap-3 px-4 py-3 border-b border-black/5 dark:border-white/5">
                                <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <MailIcon class="stroke-gray-400 size-5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500 mb-1">Email</p>
                                    <div class="truncate text-sm text-gray-700 dark:text-gray-300">
                                        <p v-if="user?.email == null"
                                            class="italic text-xs text-gray-600 dark:text-gray-400">hidden</p>
                                        <p v-else>{{ user?.email }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 px-4 py-3 border-b border-black/5 dark:border-white/5">
                                <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <BookUserIcon class="stroke-gray-400 size-5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500 mb-1">Bio</p>
                                    <div class="truncate text-sm text-gray-700 dark:text-gray-300">
                                        <p v-if="user?.bio == null"
                                            class="italic text-xs text-gray-600 dark:text-gray-400">Not Filled</p>
                                        <p v-else>{{ user?.bio }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 px-4 py-3 border-b border-black/5 dark:border-white/5">
                                <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <CalenderPlusIcon class="stroke-gray-400 size-5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500 mb-1">User Joined</p>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">
                                        {{ formatDate(user?.created_at) }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 px-4 py-3">
                                <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <CalenderRangeIcon class="stroke-gray-400 size-5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500 mb-1">Conversation Started</p>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">
                                        {{ formatDate(chat?.created_at) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="mt-6">
                        <h3 class="px-1 mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">
                            Activity
                        </h3>
                        <div
                            class="overflow-hidden rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                            <div class="flex items-center gap-3 px-4 py-3">
                                <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <CalenderRangeIcon class="stroke-gray-400 size-5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500 mb-1">Last message</p>
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                        {{ formatLastMessageDate(chat?.last_message_created_at) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>
                </template>

                <!-- ================= GROUP CHAT ================= -->
                <template v-else-if="chat?.type === 'group'">
                    <section>
                        <h3 class="px-1 mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">
                            Group information
                        </h3>
                        <div
                            class="overflow-hidden rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                            <div class="flex items-center gap-3 px-4 py-3 border-b border-black/5 dark:border-white/5">
                                <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <UsersIcon class="stroke-gray-400 size-5" />
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Members</p>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">
                                        {{ chat?.members_count ?? 'Unknown' }}
                                        <span v-if="chat?.members_count">
                                            {{ chat.members_count === 1 ? 'member' : 'members' }}
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 px-4 py-3 border-b border-black/5 dark:border-white/5">
                                <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <BookUserIcon class="stroke-gray-400 size-5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500 mb-1">Description</p>
                                    <div class="truncate text-sm text-gray-700 dark:text-gray-300">
                                        <p v-if="chat?.description == null"
                                            class="italic text-xs text-gray-600 dark:text-gray-400">Not Filled</p>
                                        <p v-else>{{ chat?.description }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 px-4 py-3">
                                <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <CalenderRangeIcon class="stroke-gray-400 size-5" />
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Group created</p>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">
                                        {{ formatDate(chat?.created_at) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="mt-6">
                        <div class="flex items-center justify-between px-1 mb-2">
                            <h3 class="text-xs font-medium uppercase tracking-wider text-gray-500">Members</h3>
                            <button type="button"
                                class="text-xs text-gray-500 hover:text-gray-800 dark:hover:text-gray-300 transition-colors cursor-pointer">
                                See all
                            </button>
                        </div>
                        <div
                            class="flex items-center justify-center h-20 rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                            <p class="text-sm text-gray-500">Members will appear here</p>
                        </div>
                    </section>

                    <section class="mt-6">
                        <h3 class="px-1 mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">Activity</h3>
                        <div
                            class="px-4 py-3 rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                            <p class="text-xs text-gray-500">Last message</p>
                            <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                {{ formatLastMessageDate(chat?.last_message_created_at) }}
                            </p>
                        </div>
                    </section>
                </template>

                <!-- ================= ACTIONS ================= -->
                <section class="mt-6">
                    <h3 class="px-1 mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">
                        Actions
                    </h3>

                    <div
                        class="overflow-hidden rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                        <button type="button" @click="handleSearch"
                            class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                            <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                <TextSearchIcon class="stroke-gray-400 size-5" />
                            </span>
                            Search in conversation
                        </button>

                        <template v-if="chat?.type === 'direct'">
                            <button v-if="!isContact" type="button" @click="handleAddToContact"
                                class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                                <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <UserPlusIcon class="stroke-gray-400 size-5" />
                                </span>
                                Add to Contact
                            </button>

                            <template v-else>
                                <button type="button" @click="handleEditContact"
                                    class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                                    <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                        <UserIcon class="stroke-gray-400 size-5" />
                                    </span>
                                    Edit Contact
                                </button>

                                <button type="button" @click="handleRemoveFromContact"
                                    class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                                    <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                        <UserMinusIcon class="stroke-gray-400 size-5" />
                                    </span>
                                    Remove from Contact
                                </button>
                            </template>
                        </template>

                        <button type="button" @click="handleToggleMute"
                            class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                            <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                <BellIcon v-if="!chat?.muted" class="stroke-gray-400 size-5" />
                                <BellSlashIcon v-else class="stroke-gray-400 size-5" />
                            </span>
                            {{ chat?.muted ? 'Unmute notifications' : 'Mute notifications' }}
                        </button>
                    </div>
                </section>

                <!-- ================= DANGER ACTIONS ================= -->
                <section class="mt-6 pb-2">
                    <template v-if="chat?.type === 'direct'">
                        <button type="button" @click="handleClearChat"
                            class="w-full cursor-pointer px-4 py-3 rounded-xl text-sm text-red-400 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 transition-colors">
                            Clear chat
                        </button>

                        <button type="button" @click="handleDeleteChat"
                            class="w-full cursor-pointer mt-2 px-4 py-3 rounded-xl text-sm text-red-400 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 transition-colors">
                            Delete chat
                        </button>

                        <button type="button" @click="handleBlockUser"
                            class="w-full cursor-pointer mt-2 px-4 py-3 rounded-xl text-sm text-red-400 hover:bg-red-500/5 transition-colors">
                            Block user
                        </button>
                    </template>

                    <template v-else-if="chat?.type === 'group'">
                        <button type="button" @click="handleClearChat"
                            class="w-full cursor-pointer px-4 py-3 rounded-xl text-sm text-red-400 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 transition-colors">
                            Clear chat
                        </button>

                        <button type="button" @click="handleDeleteChat"
                            class="w-full cursor-pointer mt-2 px-4 py-3 rounded-xl text-sm text-red-400 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 transition-colors">
                            Delete chat
                        </button>

                        <button type="button" @click="handleLeaveChat"
                            class="w-full cursor-pointer mt-2 px-4 py-3 rounded-xl text-sm text-red-400 hover:bg-red-500/5 transition-colors">
                            Leave group
                        </button>
                    </template>
                </section>
            </div>
        </div>
    </Modal>
</template>