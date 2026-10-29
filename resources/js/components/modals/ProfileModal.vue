<!-- src/components/modals/ProfileModal.vue -->
<script setup lang="ts">
import { computed, ref } from 'vue';
import useAuthStore from '@/stores/auth';
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
import MegaphoneIcon from '@/assets/svgs/Megaphone.svg';
import UserPlusIcon from '@/assets/svgs/UserPlus.svg';
import UserMinusIcon from '@/assets/svgs/UserMinus.svg';
import UserIcon from '@/assets/svgs/User.svg';
import EditIcon from '@/assets/svgs/Edit.svg';
import Avatar from '../chat/Avatar.vue';
import type { Chat, ChatMember, User } from '@/types';
import { getChatBySlug, setChatMute, clearChatHistory, deleteChat, getChatMembers, setChatMemberRole, removeChatMember, regenerateInviteLink, updateChat, joinChat, } from '@/api/index.ts';
import ProfileImageGallery from '@/components/modals/ProfileImageGallery.vue';
import router from '@/router';

type ProfileTab = 'info' | 'members' | 'files' | 'media' | 'links' | 'music';
const SLUG_PATTERN = /^[A-Za-z0-9_-]{3,127}$/;

const emit = defineEmits<{
    (e: 'openEditContactModal', username: string, name?: string | null, editing?: boolean): void;
    (e: 'openAddMembers', chat: Chat): void;
}>();

const { modal, open: modalOpen, close, toggle, isOpen } = useModalControl();
const chatStore = useChatStore();
const auth = useAuthStore();
const contactStore = useContactStore();
const { confirm } = useConfirm();

const chatSlug = ref<string | null>(null);
const chat = ref<Chat | null>(null);
const user = ref<User | null>(null);
const isLoading = ref<boolean>(true);
const profileImageGallery = ref<InstanceType<typeof ProfileImageGallery> | null>(null);
const activeTab = ref<ProfileTab>('info');

/* ------------------------------------------------------- groups & channels */

const isGroupLike = computed(() => chat.value?.type === 'group' || chat.value?.type === 'channel');
const isChannel = computed(() => chat.value?.type === 'channel');
const kind = computed(() => (isChannel.value ? 'channel' : 'group'));
const isMember = computed(() => chat.value?.is_member !== false);
const myRole = computed(() => chat.value?.my_role ?? null);
const isOwner = computed(() => myRole.value === 'owner');
const isAdmin = computed(() => myRole.value === 'owner' || myRole.value === 'admin');
const showHandle = computed(() => !!chat.value?.slug && !chat.value?.is_private);
const memberNoun = computed(() => (isChannel.value ? 'subscriber' : 'member'));
const canSeeMembers = computed(() => isGroupLike.value && isMember.value && (!isChannel.value || isAdmin.value));
const inviteLink = computed(() =>
    chat.value?.is_private && chat.value?.invite_code ? `${window.location.origin}/join/${chat.value.invite_code}` : '',
);

const members = ref<ChatMember[]>([]);
const membersPage = ref(1);
const membersLastPage = ref(1);
const membersLoading = ref(false);

// Filter and Sort states for members list
const memberRoleFilter = ref<'all' | 'admins' | 'normal'>('all');
const memberSort = ref<'name' | 'joined_at' | 'role'>('role');
const memberDirection = ref<'asc' | 'desc'>('asc');

let membersToken = 0;

async function loadMembers(page = 1): Promise<void> {
    const c = chat.value;
    if (!c?.id || !canSeeMembers.value) return;
    const token = ++membersToken;
    membersLoading.value = true;
    try {
        const res = await getChatMembers(c.id, page, {
            role: memberRoleFilter.value,
            sort: memberSort.value,
            direction: memberDirection.value,
        });
        if (token !== membersToken) return;
        members.value = res.data;
        membersPage.value = res.meta?.current_page ?? page;
        membersLastPage.value = res.meta?.last_page ?? 1;
    } catch (error) {
        if (token === membersToken) console.error('Failed to load members', error);
    } finally {
        if (token === membersToken) membersLoading.value = false;
    }
}

function handleMemberFilterOrSortChange(): void {
    void loadMembers(1);
}

const isSelf = (m: ChatMember) => m.id === auth.user?.id;
const canManage = (m: ChatMember) => isAdmin.value && !isSelf(m) && m.role !== 'owner' && (m.role !== 'admin' || isOwner.value);
const roleLabel = (role: ChatMember['role']) => (role === 'owner' ? 'Owner' : role === 'admin' ? 'Admin' : '');

function adjustMembersCount(delta: number): void {
    if (chat.value?.members_count != null) {
        chat.value.members_count = Math.max(0, chat.value.members_count + delta);
    }
}

async function toggleAdmin(m: ChatMember): Promise<void> {
    if (!chat.value?.id || m.id == null) return;
    const role = m.role === 'admin' ? 'normal' : 'admin';
    try {
        await setChatMemberRole(chat.value.id, m.id, role);
        m.role = role;
    } catch (error) {
        console.error('Failed to change role', error);
    }
}

async function handleRemoveMember(m: ChatMember): Promise<void> {
    if (!chat.value?.id || m.id == null) return;
    const confirmed = await confirm({
        title: `Remove ${isChannel.value ? 'subscriber' : 'member'}`,
        message: `Remove ${m.name || m.username} from "${chat.value.name || chat.value.slug}"?`,
        confirmText: 'Remove',
        variant: 'danger',
    });
    if (!confirmed) return;
    try {
        await removeChatMember(chat.value.id, m.id);
        members.value = members.value.filter((x) => x.id !== m.id);
        adjustMembersCount(-1);
    } catch (error) {
        console.error('Failed to remove member', error);
    }
}

const copied = ref(false);

async function copyInviteLink(): Promise<void> {
    if (!inviteLink.value) return;
    try {
        await navigator.clipboard.writeText(inviteLink.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch (error) {
        console.error('Failed to copy invite link', error);
    }
}

async function handleRegenerateInvite(): Promise<void> {
    if (!chat.value?.id) return;
    const confirmed = await confirm({
        title: 'New invite link',
        message: 'The current link will stop working. Continue?',
        confirmText: 'Regenerate',
        variant: 'warning',
    });
    if (!confirmed) return;
    try {
        chat.value.invite_code = await regenerateInviteLink(chat.value.id);
    } catch (error) {
        console.error('Failed to regenerate invite link', error);
    }
}

/* ---- edit info ---- */
const editing = ref(false);
const editName = ref('');
const editDescription = ref('');
const editIsPrivate = ref(false);
const editSlug = ref('');
const saving = ref(false);
const editError = ref('');

const editSlugInvalid = computed(() => !editIsPrivate.value && editSlug.value.trim() !== '' && !SLUG_PATTERN.test(editSlug.value.trim()));

function startEditInfo(): void {
    editName.value = chat.value?.name ?? '';
    editDescription.value = chat.value?.description ?? '';
    editIsPrivate.value = chat.value?.is_private ?? false;
    editSlug.value = chat.value?.slug ?? '';
    editError.value = '';
    editing.value = true;
}

async function saveEditInfo(): Promise<void> {
    const c = chat.value;
    const name = editName.value.trim();
    if (!c?.id || !name || saving.value || editSlugInvalid.value) return;
    saving.value = true;
    editError.value = '';
    try {
        const payload: Parameters<typeof updateChat>[1] = {
            name,
            description: editDescription.value.trim() || null,
        };
        if (isOwner.value) {
            payload.is_private = editIsPrivate.value;
            if (!editIsPrivate.value) {
                payload.slug = editSlug.value.trim() || null;
            }
        }
        const updated = await updateChat(c.id, payload);
        c.name = updated.name;
        c.description = updated.description;
        c.is_private = updated.is_private;
        c.slug = updated.slug;
        editing.value = false;
    } catch (error) {
        editError.value = 'Could not save changes.';
        console.error('Failed to update chat', error);
    } finally {
        saving.value = false;
    }
}

const joining = ref(false);

async function handleJoin(): Promise<void> {
    const id = chat.value?.id;
    if (id == null || joining.value) return;
    joining.value = true;
    try {
        const joined = await joinChat(id);
        chat.value = chatStore.upsertChat(joined);
        void loadMembers(1);
    } catch (error) {
        console.error('Failed to join chat', error);
    } finally {
        joining.value = false;
    }
}

async function handleLeaveGroup(): Promise<void> {
    const c = chat.value;
    if (!c?.id) return;
    const owner = isOwner.value;
    const confirmed = await confirm(
        owner
            ? { title: `Delete ${kind.value}`, message: `Delete "${c.name || c.slug}" for all members?`, confirmText: 'Delete', variant: 'danger' }
            : { title: `Leave ${kind.value}`, message: `Leave "${c.name || c.slug}"?`, confirmText: 'Leave', variant: 'danger' },
    );
    if (!(typeof confirmed === 'boolean' ? confirmed : confirmed.confirmed)) return;

    try {
        const chatId = c.id;
        await deleteChat(chatId, owner);
        chatStore.removeChat(chatId);
        if (chatStore.currentChat?.id == chatId) {
            router.push('/');
        }
        close();
    } catch (error) {
        console.error('Failed to leave / delete chat', error);
    }
}

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

async function open(slug: string, c: Chat | null): Promise<void> {
    if (!slug) return;
    if (contactStore.contacts === null) {
        contactStore.loadContacts();
    }

    profileImageGallery.value?.destroyPhotoSwipe();
    chat.value = c;
    user.value = null;
    chatSlug.value = slug;
    editing.value = false;
    members.value = [];
    activeTab.value = 'info';
    memberRoleFilter.value = 'all';
    memberSort.value = 'role';
    memberDirection.value = 'asc';

    profileImageGallery.value?.resetPhotos(slug);
    if (c == null || !(c.type === 'group' || c.type === 'channel')) {
        const res = await getChatBySlug(slug);
        chat.value = res.chat;
        user.value = res.user;
        if (chat.value == null) {
            chat.value = { slug: user.value?.username ?? slug, type: 'direct' };
        }
    }
    modalOpen();
    if (canSeeMembers.value) {
        void loadMembers(1);
    }
}

function handleAvatarClick() {
    profileImageGallery.value?.openForProfile(
        chatSlug.value || chat.value?.slug || '',
        chat.value?.profile_photo,
    );
}

function formatDate(date?: string | null): string {
    if (!date) return 'Unknown';
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(date));
}

function formatLastMessageDate(date?: string | null): string {
    if (!date) return 'No messages yet';
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(date));
}

function handleSearch(): void {
    close();
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

async function handleRemoveFromContact(): Promise<void> {
    const username = user.value?.username || chat.value?.slug;
    if (!username) return;
    const confirmed = await confirm({ title: 'Delete Contact', message: `Delete ${username} from your contacts?`, confirmText: 'Delete', variant: 'danger' });
    if (!confirmed) return;
    try {
        await contactStore.removeContact(username);
    } catch (error) {
        console.error('Failed to remove contact', error);
    }
}

async function handleToggleMute(): Promise<void> {
    if (!chat.value?.id) return;
    try {
        const newMuted = !chat.value.muted;
        const res = await setChatMute(chat.value.id, newMuted ? 'forever' : null);
        chat.value.muted = res.muted;
        chat.value.muted_until = res.muted_until;
    } catch (error) {
        console.error('Failed to toggle mute', error);
    }
}

async function handleClearChat(): Promise<void> {
    if (!chat.value?.id) return;
    const res = await confirm({ title: 'Clear Chat History', message: `Clear history for ${chat.value.name || chat.value.slug}?`, confirmText: 'Clear', variant: 'warning' });
    const confirmed = typeof res === 'boolean' ? res : res.confirmed;
    if (!confirmed) return;
    try {
        await clearChatHistory(chat.value.id, false);
    } catch (error) {
        console.error('Failed to clear history', error);
    }
}

async function handleDeleteChat(): Promise<void> {
    if (!chat.value?.id) return;
    const res = await confirm({ title: 'Delete Chat', message: `Delete chat with ${chat.value.name || chat.value.slug}?`, confirmText: 'Delete', variant: 'danger' });
    const confirmed = typeof res === 'boolean' ? res : res.confirmed;
    if (!confirmed) return;
    try {
        const chatId = chat.value.id;
        await deleteChat(chatId, true);
        chatStore.removeChat(chatId);
        if (chatStore.currentChat?.id == chatId) {
            router.push('/');
        }
        close();
    } catch (error) {
        console.error('Failed to delete chat', error);
    }
}

defineExpose({
    open,
    close,
    toggle,
    isOpen,
    reloadMembers: () => loadMembers(1),
});
</script>

<template>
    <Modal ref="modal" width-class="max-w-[500px] w-[90%]" height-class="h-[80%]">
        <ProfileImageGallery ref="profileImageGallery" />
        <div class="relative flex flex-col h-full max-h-[95vh]">
            <button type="button" @click="close"
                class="absolute top-2 left-2 z-10 p-3 rounded-full cursor-pointer text-gray-400 hover:text-gray-500 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                aria-label="Close">
                <CloseIcon class="size-6" />
            </button>

            <!-- Header -->
            <div
                class="flex flex-col items-center px-6 pt-8 pb-4 border-b border-gray-100 dark:border-gray-800 shrink-0">
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
                    <p class="mt-1 text-sm text-gray-500">@{{ chatSlug }}</p>
                </template>
                <template v-else-if="isGroupLike">
                    <p class="flex items-center gap-1.5 mt-1 text-sm text-gray-500">
                        <MegaphoneIcon v-if="isChannel" class="size-4" />
                        <UsersIcon v-else class="size-4" />
                        <span v-if="showHandle">@{{ chat?.slug }}</span>
                        <span v-else>{{ isChannel ? 'Private channel' : 'Private group' }}</span>
                        <span v-if="chat?.members_count != null">
                            · {{ chat.members_count }} {{ memberNoun }}{{ chat.members_count === 1 ? '' : 's' }}
                        </span>
                    </p>
                </template>

                <!-- Navigation Tabs -->
                <div
                    class="flex gap-1 mt-4 p-1 bg-gray-100 dark:bg-gray-800 rounded-xl w-full overflow-x-auto scrollable text-xs">
                    <button type="button"
                        class="flex-1 py-1.5 px-3 rounded-lg font-medium transition-colors cursor-pointer text-center whitespace-nowrap"
                        :class="activeTab === 'info' ? 'bg-white dark:bg-gray-700 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                        @click="activeTab = 'info'">Info</button>
                    <button v-if="canSeeMembers" type="button"
                        class="flex-1 py-1.5 px-3 rounded-lg font-medium transition-colors cursor-pointer text-center whitespace-nowrap"
                        :class="activeTab === 'members' ? 'bg-white dark:bg-gray-700 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                        @click="activeTab = 'members'">Members</button>
                    <button type="button"
                        class="flex-1 py-1.5 px-3 rounded-lg font-medium transition-colors cursor-pointer text-center whitespace-nowrap"
                        :class="activeTab === 'files' ? 'bg-white dark:bg-gray-700 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                        @click="activeTab = 'files'">Files</button>
                    <button type="button"
                        class="flex-1 py-1.5 px-3 rounded-lg font-medium transition-colors cursor-pointer text-center whitespace-nowrap"
                        :class="activeTab === 'media' ? 'bg-white dark:bg-gray-700 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                        @click="activeTab = 'media'">Media</button>
                    <button type="button"
                        class="flex-1 py-1.5 px-3 rounded-lg font-medium transition-colors cursor-pointer text-center whitespace-nowrap"
                        :class="activeTab === 'links' ? 'bg-white dark:bg-gray-700 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                        @click="activeTab = 'links'">Links</button>
                    <button type="button"
                        class="flex-1 py-1.5 px-3 rounded-lg font-medium transition-colors cursor-pointer text-center whitespace-nowrap"
                        :class="activeTab === 'music' ? 'bg-white dark:bg-gray-700 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                        @click="activeTab = 'music'">Music</button>
                </div>
            </div>

            <!-- Tab Content (Non-Members scroll naturally, Members uses internal strict layout to fix footer at bottom) -->
            <div class="flex-1 overflow-y-auto scrollable px-5 py-4"
                :class="{ 'flex flex-col min-h-0 overflow-hidden p-0': activeTab === 'members' }">
                <!-- ================= TAB: INFO ================= -->
                <template v-if="activeTab === 'info'">
                    <template v-if="chat?.type === 'direct'">
                        <section>
                            <h3 class="px-1 mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">Information
                            </h3>
                            <div
                                class="overflow-hidden rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                                <div
                                    class="flex items-center gap-3 px-4 py-3 border-b border-black/5 dark:border-white/5">
                                    <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                        <MailIcon class="stroke-gray-400 size-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs text-gray-500 mb-1">Email</p>
                                        <p class="truncate text-sm text-gray-700 dark:text-gray-300">{{ user?.email ??
                                            'hidden' }}</p>
                                    </div>
                                </div>
                                <div
                                    class="flex items-center gap-3 px-4 py-3 border-b border-black/5 dark:border-white/5">
                                    <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                        <BookUserIcon class="stroke-gray-400 size-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs text-gray-500 mb-1">Bio</p>
                                        <p class="truncate text-sm text-gray-700 dark:text-gray-300">{{ user?.bio ??
                                            'Not Filled' }}</p>
                                    </div>
                                </div>
                                <div
                                    class="flex items-center gap-3 px-4 py-3 border-b border-black/5 dark:border-white/5">
                                    <div class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                        <CalenderPlusIcon class="stroke-gray-400 size-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs text-gray-500 mb-1">User Joined</p>
                                        <p class="text-sm text-gray-700 dark:text-gray-300">{{
                                            formatDate(user?.created_at) }}</p>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isGroupLike">
                        <button v-if="!isMember" type="button" :disabled="joining" @click="handleJoin"
                            class="w-full px-4 py-3 mb-6 text-sm font-medium text-white transition-colors bg-blue-600 cursor-pointer rounded-xl hover:bg-blue-700 disabled:opacity-60">
                            {{ joining ? 'Joining…' : `Join ${kind}` }}
                        </button>

                        <section>
                            <div class="flex items-center justify-between px-1 mb-2">
                                <h3 class="text-xs font-medium tracking-wider text-gray-500 uppercase">{{ isChannel ?
                                    'Channel' : 'Group' }} information</h3>
                                <button v-if="isAdmin && !editing" type="button" @click="startEditInfo"
                                    class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/40 rounded-xl hover:bg-blue-100 transition-all cursor-pointer shadow-xs"
                                    aria-label="Edit group info">
                                    <EditIcon class="size-4" /> Edit Chat Info
                                </button>
                            </div>

                            <!-- Edit Form -->
                            <form v-if="editing"
                                class="flex flex-col gap-3.5 p-4 rounded-2xl bg-white dark:bg-gray-800/80 border border-blue-500/20 shadow-md"
                                @submit.prevent="saveEditInfo">
                                <label class="flex flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                    Name
                                    <input v-model="editName" type="text" maxlength="255" required aria-label="Name"
                                        class="w-full px-3 py-2 text-sm text-gray-900 bg-gray-50 border border-gray-200 rounded-xl outline-none dark:bg-gray-900 dark:border-gray-700 dark:text-gray-100 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20" />
                                </label>

                                <label class="flex flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                    Description
                                    <textarea v-model="editDescription" rows="2" maxlength="512"
                                        aria-label="Description" placeholder="Description"
                                        class="w-full px-3 py-2 text-sm text-gray-900 bg-gray-50 border border-gray-200 rounded-xl outline-none resize-none dark:bg-gray-900 dark:border-gray-700 dark:text-gray-100 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"></textarea>
                                </label>

                                <template v-if="isOwner">
                                    <fieldset class="flex flex-col gap-1.5 pt-1">
                                        <legend class="text-xs font-medium text-gray-600 dark:text-gray-300">Chat
                                            Privacy</legend>
                                        <div class="grid grid-cols-2 gap-2">
                                            <label
                                                class="flex items-center gap-2 px-3 py-2 text-xs font-medium rounded-xl border cursor-pointer transition-colors"
                                                :class="!editIsPrivate ? 'border-blue-500 bg-blue-50/50 dark:bg-blue-950/30 text-blue-700 dark:text-blue-300' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300'">
                                                <input v-model="editIsPrivate" type="radio" :value="false"
                                                    class="accent-blue-600" />
                                                Public
                                            </label>
                                            <label
                                                class="flex items-center gap-2 px-3 py-2 text-xs font-medium rounded-xl border cursor-pointer transition-colors"
                                                :class="editIsPrivate ? 'border-blue-500 bg-blue-50/50 dark:bg-blue-950/30 text-blue-700 dark:text-blue-300' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300'">
                                                <input v-model="editIsPrivate" type="radio" :value="true"
                                                    class="accent-blue-600" />
                                                Private
                                            </label>
                                        </div>
                                    </fieldset>

                                    <label v-if="!editIsPrivate"
                                        class="flex flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                                        Public Link / Slug
                                        <input v-model="editSlug" type="text" maxlength="127" autocomplete="off"
                                            autocapitalize="off"
                                            class="w-full px-3 py-2 text-sm text-gray-900 bg-gray-50 border border-gray-200 rounded-xl outline-none dark:bg-gray-900 dark:border-gray-700 dark:text-gray-100 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                                            placeholder="group_username" />
                                        <span v-if="editSlugInvalid" class="text-xs text-red-500">Use 3+ letters,
                                            numbers, _ or -</span>
                                    </label>
                                </template>

                                <p v-if="editError" role="alert" class="text-xs text-red-500">{{ editError }}</p>
                                <div class="flex justify-end gap-2 pt-1">
                                    <button type="button"
                                        class="px-4 py-2 text-xs font-medium text-gray-600 rounded-full cursor-pointer dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
                                        @click="editing = false">Cancel</button>
                                    <button type="submit" :disabled="saving || !editName.trim() || editSlugInvalid"
                                        class="px-5 py-2 text-xs font-medium text-white bg-blue-600 rounded-full cursor-pointer hover:bg-blue-700 disabled:opacity-50 shadow-sm">
                                        {{ saving ? 'Saving…' : 'Save Changes' }}
                                    </button>
                                </div>
                            </form>

                            <div v-else
                                class="overflow-hidden rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                                <div
                                    class="flex items-center gap-3 px-4 py-3 border-b border-black/5 dark:border-white/5">
                                    <div class="flex items-center justify-center rounded-full w-9 h-9 bg-white/5">
                                        <BookUserIcon class="stroke-gray-400 size-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="mb-1 text-xs text-gray-500">Description</p>
                                        <p class="text-sm text-gray-700 break-words dark:text-gray-300">{{
                                            chat?.description || 'Not Filled' }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 px-4 py-3">
                                    <div class="flex items-center justify-center rounded-full w-9 h-9 bg-white/5">
                                        <CalenderRangeIcon class="stroke-gray-400 size-5" />
                                    </div>
                                    <div>
                                        <p class="mb-1 text-xs text-gray-500">Created</p>
                                        <p class="text-sm text-gray-700 dark:text-gray-300">{{
                                            formatDate(chat?.created_at) }}</p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section v-if="isAdmin && inviteLink" class="mt-6">
                            <h3 class="px-1 mb-2 text-xs font-medium tracking-wider text-gray-500 uppercase">Invite link
                            </h3>
                            <div
                                class="flex flex-col gap-2 px-4 py-3 rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                                <p class="text-sm text-gray-700 break-all dark:text-gray-300">{{ inviteLink }}</p>
                                <div class="flex gap-4">
                                    <button type="button"
                                        class="text-sm font-medium text-blue-600 cursor-pointer hover:underline"
                                        @click="copyInviteLink">{{ copied ? 'Copied!' : 'Copy' }}</button>
                                    <button type="button" class="text-sm text-gray-500 cursor-pointer hover:underline"
                                        @click="handleRegenerateInvite">Regenerate</button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <!-- ACTIONS -->
                    <section class="mt-6">
                        <h3 class="px-1 mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">Actions</h3>
                        <div
                            class="overflow-hidden rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                            <button type="button" @click="handleSearch"
                                class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 transition-colors">
                                <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <TextSearchIcon class="stroke-gray-400 size-5" />
                                </span>
                                Search in conversation
                            </button>
                            <template v-if="chat?.type === 'direct'">
                                <button v-if="!isContact" type="button" @click="handleAddToContact"
                                    class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 transition-colors">
                                    <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                        <UserPlusIcon class="stroke-gray-400 size-5" />
                                    </span>
                                    Add to Contact
                                </button>
                                <template v-else>
                                    <button type="button" @click="handleEditContact"
                                        class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 transition-colors">
                                        <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                            <UserIcon class="stroke-gray-400 size-5" />
                                        </span>
                                        Edit Contact
                                    </button>
                                    <button type="button" @click="handleRemoveFromContact"
                                        class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 transition-colors">
                                        <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                            <UserMinusIcon class="stroke-gray-400 size-5" />
                                        </span>
                                        Remove from Contact
                                    </button>
                                </template>
                            </template>
                            <button v-if="isMember" type="button" @click="handleToggleMute"
                                class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 transition-colors">
                                <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                    <BellIcon v-if="!chat?.muted" class="stroke-gray-400 size-5" />
                                    <BellSlashIcon v-else class="stroke-gray-400 size-5" />
                                </span>
                                {{ chat?.muted ? 'Unmute notifications' : 'Mute notifications' }}
                            </button>
                        </div>
                    </section>

                    <!-- DANGER ACTIONS -->
                    <section class="mt-6 pb-2">
                        <template v-if="chat?.type === 'direct'">
                            <button type="button" @click="handleClearChat"
                                class="w-full cursor-pointer px-4 py-3 rounded-xl text-sm text-red-400 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 transition-colors">Clear
                                chat</button>
                            <button type="button" @click="handleDeleteChat"
                                class="w-full cursor-pointer mt-2 px-4 py-3 rounded-xl text-sm text-red-400 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 transition-colors">Delete
                                chat</button>
                        </template>
                        <template v-else-if="isGroupLike && isMember">
                            <button type="button" @click="handleLeaveGroup"
                                class="w-full cursor-pointer px-4 py-3 rounded-xl text-sm text-red-400 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 transition-colors">
                                {{ isOwner ? `Delete ${kind}` : `Leave ${kind}` }}
                            </button>
                        </template>
                    </section>
                </template>

                <!-- ================= TAB: MEMBERS (Scrollable List with Fixed Filter/Pagination Footer) ================= -->
                <template v-else-if="activeTab === 'members' && canSeeMembers">
                    <div class="flex flex-col h-full min-h-0 px-5 py-4">
                        <!-- Header & Role Filter / Sorting Toolbar -->
                        <div class="flex flex-col gap-2.5 mb-3 shrink-0">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ memberNoun }}s
                                    list</h3>
                                <button v-if="isAdmin && chat" type="button"
                                    class="px-3 py-1 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg cursor-pointer transition-colors"
                                    @click="emit('openAddMembers', chat)">
                                    Add {{ memberNoun }}s
                                </button>
                            </div>

                            <!-- Filter & Sort Bar (Admin & Owner Only Role Filter) -->
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <select v-if="isAdmin" v-model="memberRoleFilter"
                                    @change="handleMemberFilterOrSortChange" aria-label="Filter by role"
                                    class="px-2.5 py-1.5 bg-gray-100 dark:bg-gray-800 rounded-lg border border-transparent outline-none text-gray-700 dark:text-gray-300">
                                    <option value="all">All Roles</option>
                                    <option value="admins">Admins & Owner</option>
                                    <option value="normal">Normal Members</option>
                                </select>

                                <select v-model="memberSort" @change="handleMemberFilterOrSortChange"
                                    aria-label="Sort members by"
                                    class="px-2.5 py-1.5 bg-gray-100 dark:bg-gray-800 rounded-lg border border-transparent outline-none text-gray-700 dark:text-gray-300">
                                    <option value="role">Sort by Role</option>
                                    <option value="name">Sort by Name</option>
                                    <option value="joined_at">Sort by Join Date</option>
                                </select>

                                <button type="button"
                                    class="px-2.5 py-1.5 bg-gray-100 dark:bg-gray-800 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 cursor-pointer"
                                    @click="memberDirection = memberDirection === 'asc' ? 'desc' : 'asc'; handleMemberFilterOrSortChange()">
                                    {{ memberDirection === 'asc' ? 'Ascending ↑' : 'Descending ↓' }}
                                </button>
                            </div>
                        </div>

                        <!-- Scrollable Members List Container -->
                        <div
                            class="flex-1 min-h-0 overflow-y-auto scrollable rounded-xl bg-black/[0.03] dark:bg-white/[0.03] border border-black/5 dark:border-white/5">
                            <p v-if="membersLoading && !members.length"
                                class="px-4 py-6 text-sm text-center text-gray-500">Loading…</p>
                            <p v-else-if="!members.length" class="px-4 py-6 text-sm text-center text-gray-500">No
                                members found</p>
                            <ul v-else>
                                <li v-for="m in members" :key="m.id"
                                    class="flex items-center gap-3 px-4 py-2.5 border-b border-black/5 dark:border-white/5 last:border-b-0">
                                    <Avatar class="size-10 shrink-0"
                                        :src="m.profile_photo || '/storage/default-profile.png'" />
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-800 truncate dark:text-gray-200">
                                            {{ m.name || m.username }}<span v-if="isSelf(m)" class="text-gray-500">
                                                (you)</span>
                                        </p>
                                        <p class="text-xs text-gray-500 truncate">@{{ m.username }}</p>
                                    </div>
                                    <span v-if="roleLabel(m.role)"
                                        class="px-2 py-0.5 text-xs text-blue-700 bg-blue-100 rounded-full dark:bg-blue-900/40 dark:text-blue-200">
                                        {{ roleLabel(m.role) }}
                                    </span>
                                    <template v-if="canManage(m)">
                                        <button v-if="isOwner" type="button"
                                            class="text-xs text-gray-500 cursor-pointer hover:text-gray-800"
                                            @click="toggleAdmin(m)">
                                            {{ m.role === 'admin' ? 'Demote' : 'Make admin' }}
                                        </button>
                                        <button type="button"
                                            class="text-xs text-red-400 cursor-pointer hover:text-red-500"
                                            @click="handleRemoveMember(m)">Remove</button>
                                    </template>
                                </li>
                            </ul>
                        </div>

                        <!-- Numeric Pagination Footer (Always Visible at Bottom) -->
                        <div v-if="membersLastPage > 1"
                            class="flex items-center justify-between mt-3 px-1 text-xs text-gray-500 shrink-0">
                            <span>Page {{ membersPage }} of {{ membersLastPage }}</span>
                            <div class="flex gap-1.5">
                                <button type="button" :disabled="membersPage <= 1 || membersLoading"
                                    class="px-3 py-1 rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-40 cursor-pointer"
                                    @click="loadMembers(membersPage - 1)">Previous</button>
                                <button type="button" :disabled="membersPage >= membersLastPage || membersLoading"
                                    class="px-3 py-1 rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-40 cursor-pointer"
                                    @click="loadMembers(membersPage + 1)">Next</button>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ================= FUTURE TABS (EMPTY) ================= -->
                <template v-else-if="['files', 'media', 'links', 'music'].includes(activeTab)">
                    <div class="flex flex-col items-center justify-center py-16 text-center text-gray-400">
                        <p class="text-sm font-medium capitalize">No {{ activeTab }} shared yet</p>
                        <p class="text-xs mt-1">Items shared in this chat will appear here.</p>
                    </div>
                </template>
            </div>
        </div>
    </Modal>
</template>