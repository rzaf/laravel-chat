<script setup lang="ts">
import { computed, onUnmounted, ref, nextTick } from 'vue';
import { useModalControl } from '@/composables/useModalControl';
import Modal from '@/components/common/Modal.vue';
import CloseIcon from '@/assets/svgs/Close.svg';
import MailIcon from '@/assets/svgs/Mail.svg';
import TextSearchIcon from '@/assets/svgs/TextSearch.svg';
import CalenderRangeIcon from '@/assets/svgs/CalenderRange.svg';
import CalenderPlusIcon from '@/assets/svgs/CalenderPlus.svg';
import BellIcon from '@/assets/svgs/Bell.svg';
import BookUserIcon from '@/assets/svgs/BookUser.svg';
import UsersIcon from '@/assets/svgs/Users.svg'
import UserPlusIcon from '@/assets/svgs/UserPlus.svg';
import UserMinusIcon from '@/assets/svgs/UserMinus.svg';
import UserIcon from '@/assets/svgs/User.svg';
import Avatar from '../chat/Avatar.vue';
import type { Attachment, Chat, User } from '@/types';
import { getChatBySlug, getProfilePhotos } from '@/api/index.ts';

import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';

const { modal, open: modalOpen, close, toggle, isOpen } = useModalControl();

const chatSlug = ref<string | null>(null);
const chat = ref<Chat | null>(null);
const user = ref<User | null>(null);
const isLoading = ref<boolean>(true);
const chatProfileImages = ref<Attachment[] | null>(null);
const isLoadingPhotos = ref<boolean>(false);

async function LoadChat() {
    if (!chatSlug.value) return;

    isLoading.value = true;
    try {
        const res = await getChatBySlug(chatSlug.value);
        chat.value = res.chat;
        user.value = res.user;
        if (chat.value == null && user.value) {
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

    destroyPhotoSwipe();
    chat.value = c;
    user.value = null;
    chatSlug.value = slug;

    resetPhotos(slug);
    if (c == null || c.type !== 'group') {
        LoadChat();
        startFirstPage(slug); // prefetch page 1 so the click opens instantly
    }
    modalOpen();
}

function destroyPhotoSwipe() {
    syncThumbs = null;
    if (lightbox) {
        lightbox.destroy();
        lightbox = null;
    }
}
// ---------- Profile photos (paginated) ----------
type Slide = { src: string; msrc: string; width: number; height: number };

let photos: Attachment[] = [];
let photosSlug: string | null = null;
let photosPage = 0;
let photosLastPage = 1;
let isFetchingPage = false;
let firstPagePromise: Promise<void> | null = null;
let generation = 0; // invalidates in-flight requests when another profile is opened

let lightbox: PhotoSwipeLightbox | null = null;
let slides: Slide[] = [];          // the array PhotoSwipe reads from (mutated in place)
let usingPlaceholder = false;      // true while the gallery shows only the avatar
let syncThumbs: (() => void) | null = null;

function toSlide(a: Attachment): Slide {
    return {
        src: a.url,
        msrc: a.thumbnail_url || a.url,
        width: a.width || 1000,
        height: a.height || 1000,
    };
}

function resetPhotos(slug: string) {
    generation++;
    photos = [];
    photosSlug = slug;
    photosPage = 0;
    photosLastPage = 1;
    isFetchingPage = false;
    firstPagePromise = null;
}

async function fetchPhotosPage(slug: string, page: number): Promise<Attachment[] | null> {
    const gen = generation;
    isFetchingPage = true;
    try {
        const res = await getProfilePhotos(slug, page);
        if (gen !== generation) return null; // another profile was opened meanwhile
        photosPage = res.meta?.current_page ?? page;
        photosLastPage = res.meta?.last_page ?? page;
        return (res.data ?? []).filter(p => p && p.url);
    } catch (e) {
        console.error('Failed to load profile images:', e);
        return null;
    } finally {
        if (gen === generation) isFetchingPage = false;
    }
}

/** Starts loading page 1 (once). Safe to call many times. */
function startFirstPage(slug: string): Promise<void> {
    if (firstPagePromise) return firstPagePromise;

    firstPagePromise = fetchPhotosPage(slug, 1).then(atts => {
        if (atts === null) {
            firstPagePromise = null; // allow retry on next click
            return;
        }
        photos = atts;

        // Gallery already open on the placeholder avatar -> swap in the real photos
        if (lightbox?.pswp && usingPlaceholder && atts.length > 0) {
            usingPlaceholder = false;
            slides.splice(0, slides.length, ...atts.map(toSlide));
            refreshGallery(true);
        }
    });
    return firstPagePromise;
}

async function loadNextPage() {
    if (isFetchingPage || usingPlaceholder || !photosSlug) return;
    if (photosPage >= photosLastPage) return;

    const atts = await fetchPhotosPage(photosSlug, photosPage + 1);
    if (!atts || atts.length === 0) return;

    photos = [...photos, ...atts];
    slides.push(...atts.map(toSlide));
    refreshGallery(false);
}

/** Tells PhotoSwipe + thumbnail bar that `slides` changed. */
function refreshGallery(includeCurrent: boolean) {
    const pswp: any = lightbox?.pswp;
    if (!pswp) return;

    syncThumbs?.();

    const cur = pswp.currIndex as number;
    const indexes = includeCurrent ? [cur, cur - 1, cur + 1] : [cur - 1, cur + 1];
    for (const i of indexes) {
        if (i >= 0 && i < slides.length) pswp.refreshSlideContent(i);
    }
    pswp.updateSize?.(true);
}

function handleAvatarClick() {
    if (chat.value?.profile_photo == null) return;

    const slug = chatSlug.value || chat.value?.slug;
    if (!slug) return;

    if (photosSlug !== slug) resetPhotos(slug);
    startFirstPage(slug); // no await: gallery opens immediately
    openGallery();
}

function openGallery() {
    destroyPhotoSwipe();

    if (photos.length > 0) {
        usingPlaceholder = false;
        slides = photos.map(toSlide);
    } else {
        // Page 1 not here yet: show the avatar now, replace when it arrives
        usingPlaceholder = true;
        const avatar = chat.value?.profile_photo || '/storage/default-profile.png';
        slides = [{ src: avatar, msrc: avatar, width: 800, height: 800 }];
    }

    lightbox = new PhotoSwipeLightbox({
        dataSource: slides, // same array reference, so pushes are picked up
        pswpModule: () => import('photoswipe'),

        showHideAnimationType: 'fade',
        showAnimationDuration: 300,
        hideAnimationDuration: 200,

        initialZoomLevel: 'fit',
        secondaryZoomLevel: 1.5,
        maxZoomLevel: 4,

        imageClickAction: 'zoom-or-close',
        tapAction: 'toggle-controls',
        doubleTapAction: 'zoom',
        wheelToZoom: true,
        pinchToClose: true,
        closeOnVerticalDrag: true,

        padding: { top: 20, bottom: 90, left: 20, right: 20 },
        bgOpacity: 0.9,
    });

    lightbox.on('loadError', ({ content }: any) => {
        console.error('[PhotoSwipe] failed to load:', content?.data?.src);
    });

    lightbox.on('uiRegister', () => {
        lightbox?.pswp?.ui?.registerElement({
            name: 'custom-thumbnails-bar',
            order: 9,
            isButton: false,
            appendTo: 'wrapper',
            onInit: (el, pswp) => {
                el.className = 'pswp__custom-thumbnails-bar';

                const container = document.createElement('div');
                container.className = 'pswp__thumb-container scrollable-x';
                el.appendChild(container);

                const updateActive = () => {
                    container.querySelectorAll<HTMLElement>('.pswp__thumb-item').forEach((btn, i) => {
                        const active = i === pswp.currIndex;
                        btn.classList.toggle('active', active);
                        if (active) {
                            container.scrollTo({
                                left: btn.offsetLeft - container.clientWidth / 2 + btn.offsetWidth / 2,
                                behavior: 'smooth',
                            });
                        }
                    });
                };

                // (Re)build the strip from the current `slides`
                syncThumbs = () => {
                    container.innerHTML = '';
                    slides.forEach((s, index) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'pswp__thumb-item';

                        const img = document.createElement('img');
                        img.src = s.msrc;
                        img.alt = `Thumbnail ${index + 1}`;
                        btn.appendChild(img);

                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            pswp.goTo(index);
                        });
                        container.appendChild(btn);
                    });
                    el.style.display = slides.length > 1 ? '' : 'none';
                    updateActive();
                };

                // Load next page when the strip is scrolled near its end
                container.addEventListener('scroll', () => {
                    const nearEnd = container.scrollLeft + container.clientWidth >= container.scrollWidth - 120;
                    if (nearEnd) loadNextPage();
                }, { passive: true });

                pswp.on('change', () => {
                    updateActive();
                    if (pswp.currIndex >= slides.length - 3) loadNextPage();
                });

                syncThumbs();
            },
        });
    });

    lightbox.on('destroy', () => {
        syncThumbs = null;
    });

    lightbox.init();
    lightbox.loadAndOpen(0);
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
    console.log('AddToContact conversation:', chat.value?.id);
}

function handleEditContact(): void {
    console.log('EditContact conversation:', chat.value?.id);
}

function handleRemoveFromContact(): void {
    console.log('RemoveFromContact conversation:', chat.value?.id);
}

function handleMute(): void {
    console.log('Mute chat:', chat.value?.id);
}

function handleClearChat(): void {
    console.log('Clear chat:', chat.value?.id);
}

function handleLeaveChat(): void {
    console.log('Leave group:', chat.value?.id);
}

function handleBlockUser(): void {
    console.log('Block user:', chat.value?.direct_user_id);
}

onUnmounted(() => {
    destroyPhotoSwipe();
});

defineExpose({
    open,
    close,
    toggle,
    isOpen,
});
</script>

<template>
    <Modal ref="modal" width-class="lg:w-[450px] w-[90%]" height-class="h-[70%]">
        <div @keyup.escape="close()" class="relative flex flex-col h-full max-h-[80vh]">
            <!-- Close -->
            <button type="button" @click="close"
                class="absolute top-2 left-2 z-10 p-3 rounded-full cursor-pointer text-gray-400 dark:hover:text-gray-300 hover:text-gray-500 hover:bg-black/5 dark:hover:bg-white/5 transition-colors duration-150"
                aria-label="Close">
                <CloseIcon class="size-6" />
            </button>

            <!-- Header -->
            <div class="flex flex-col items-center px-6 pt-8 pb-5 border-b border-white/10">
                <!-- Avatar: click loads all profile photos and opens the gallery -->
                <button type="button" @click="handleAvatarClick"
                    class="block rounded-full overflow-hidden focus:outline-none"
                    :class="{ 'opacity-70': isLoadingPhotos, 'cursor-pointer': chat?.profile_photo != null }"
                    aria-label="View profile photos">
                    <Avatar class="w-20 h-20 pointer-events-none"
                        :src="chat?.profile_photo || '/storage/default-profile.png'" />
                </button>

                <h2 class="mt-3 text-lg font-semibold text-gray-800 dark:text-gray-200">
                    {{ chat?.name || user?.name || '...' }}
                </h2>

                <!-- Direct chat -->
                <template v-if="chat?.type === 'direct'">
                    <p class="mt-1 text-sm text-gray-500">
                        @{{ chatSlug }}
                    </p>
                </template>

                <!-- Group chat -->
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

                        <button v-if="chat?.type === 'direct'" type="button" @click="handleAddToContact"
                            class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                            <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                <UserPlusIcon class="stroke-gray-400 size-5" />
                            </span>
                            Add to Contact
                        </button>

                        <button v-if="chat?.type === 'direct'" type="button" @click="handleEditContact"
                            class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                            <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                <UserIcon class="stroke-gray-400 size-5" />
                            </span>
                            Edit Contact
                        </button>

                        <button v-if="chat?.type === 'direct'" type="button" @click="handleRemoveFromContact"
                            class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                            <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                <UserMinusIcon class="stroke-gray-400 size-5" />
                            </span>
                            Remove from Contact
                        </button>

                        <button type="button" @click="handleMute"
                            class="cursor-pointer flex items-center gap-3 w-full px-4 py-3 border-t border-black/5 dark:border-white/5 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                            <span class="flex items-center justify-center w-9 h-9 rounded-full bg-white/5">
                                <BellIcon class="stroke-gray-400 size-5" />
                            </span>
                            Mute notifications
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

                        <button type="button" @click="handleBlockUser"
                            class="w-full cursor-pointer mt-2 px-4 py-3 rounded-xl text-sm text-red-400 hover:bg-red-500/5 transition-colors">
                            Block user
                        </button>
                    </template>

                    <template v-else-if="chat?.type === 'group'">
                        <button type="button" @click="handleLeaveChat"
                            class="w-full cursor-pointer px-4 py-3 rounded-xl text-sm text-red-400 bg-red-500/5 hover:bg-red-500/10 border border-red-500/10 transition-colors">
                            Leave group
                        </button>
                    </template>
                </section>
            </div>
        </div>
    </Modal>
</template>

<style>
.pswp__custom-thumbnails-bar {
    position: absolute;
    bottom: 12px;
    left: 0;
    right: 0;
    display: flex;
    justify-content: center;
    z-index: 1050;
    pointer-events: auto;
}

.pswp__thumb-container {
    position: relative;
    /* needed so offsetLeft is relative to the strip */
    display: flex;
    gap: 8px;
    padding: 8px 12px;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    border-radius: 12px;
    max-width: 90vw;
    overflow-x: auto;
    scrollbar-width: thin;
}

.pswp__thumb-item {
    position: relative;
    width: 48px;
    height: 48px;
    min-width: 48px;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid transparent;
    opacity: 0.6;
    padding: 0;
    background: transparent;
    cursor: pointer;
    transition: opacity 0.2s ease, border-color 0.2s ease;
}

.pswp__thumb-item:hover {
    opacity: 0.9;
}

.pswp__thumb-item.active {
    opacity: 1;
    border-color: #3b82f6;
}

.pswp__thumb-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>