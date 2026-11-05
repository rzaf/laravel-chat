<!-- src/components/modals/ProfileImageGallery.vue -->
<script setup lang="ts">
import { onUnmounted, ref } from 'vue';
import type { Attachment } from '@/types';
import { getProfilePhotos, getChatProfiles } from '@/api/index.ts';

import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';
import { createOverlayId, registerOverlay, unregisterOverlay } from '@/composables/useOverlayManager';

const props = defineProps<{
    canDelete?: boolean;
}>();

const emit = defineEmits<{
    (e: 'delete', mediaId: number): void;
}>();

type Slide = {
    id?: number;
    src: string;
    msrc: string;
    width: number;
    height: number;
    type: 'image' | 'video';
};

const isGalleryOpen = ref(false);

let photos: Attachment[] = [];
let photosSlug: string | null = null;
let isChatContext = false;
let photosPage = 0;
let photosLastPage = 1;
let isFetchingPage = false;
let firstPagePromise: Promise<void> | null = null;
let externalLoadMore: (() => Promise<Attachment[] | null>) | null = null;
let generation = 0;

let lightbox: PhotoSwipeLightbox | null = null;
let slides: Slide[] = [];
let usingPlaceholder = false;
let syncThumbs: (() => void) | null = null;

function toSlide(a: Attachment): Slide {
    const isVideo = typeof a.type === 'string' && a.type.startsWith('video/');
    const rawId = a.id;
    const id = typeof rawId === 'number' ? rawId : Number(rawId);

    return {
        id: Number.isFinite(id) ? id : undefined,
        src: a.url,
        msrc: isVideo ? (a.thumbnail_url || '') : (a.thumbnail_url || a.url),
        width: a.width || 1000,
        height: a.height || 1000,
        type: isVideo ? 'video' : 'image',
    };
}

function resetPhotos(slug: string, isChat = false) {
    generation++;
    photos = [];
    photosSlug = slug;
    isChatContext = isChat;
    photosPage = 0;
    photosLastPage = 1;
    isFetchingPage = false;
    firstPagePromise = null;
    externalLoadMore = null;
}

async function fetchPhotosPage(
    slug: string,
    page: number,
): Promise<Attachment[] | null> {
    const gen = generation;
    isFetchingPage = true;

    try {
        const res = isChatContext
            ? await getChatProfiles(slug, page)
            : await getProfilePhotos(slug, page);

        if (gen !== generation) return null;

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

function startFirstPage(slug: string, isChat = false): Promise<void> {
    if (firstPagePromise) return firstPagePromise;

    firstPagePromise = fetchPhotosPage(slug, 1).then(atts => {
        if (atts === null) {
            firstPagePromise = null;
            return;
        }

        photos = atts;

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

    if (externalLoadMore) {
        const currentGeneration = generation;
        isFetchingPage = true;

        try {
            const nextItems = await externalLoadMore();
            if (currentGeneration !== generation || !nextItems?.length) return;

            const validItems = nextItems.filter(item => item && item.url);
            if (!validItems.length) return;

            photos = [...photos, ...validItems];
            slides.push(...validItems.map(toSlide));
            refreshGallery(false);
        } catch (error) {
            console.error('Failed to load more gallery media:', error);
        } finally {
            if (currentGeneration === generation) isFetchingPage = false;
        }

        return;
    }

    if (photosPage >= photosLastPage) return;

    const atts = await fetchPhotosPage(photosSlug, photosPage + 1);
    if (!atts || atts.length === 0) return;

    photos = [...photos, ...atts];
    slides.push(...atts.map(toSlide));
    refreshGallery(false);
}

function refreshGallery(includeCurrent: boolean) {
    const pswp: any = lightbox?.pswp;
    if (!pswp) return;

    syncThumbs?.();

    const cur = pswp.currIndex as number;
    const indexes = includeCurrent
        ? [cur, cur - 1, cur + 1]
        : [cur - 1, cur + 1];

    for (const i of indexes) {
        if (i >= 0 && i < slides.length) {
            pswp.refreshSlideContent(i);
        }
    }

    pswp.updateSize?.(true);
}

function openForProfile(slug: string, avatar: string | null | undefined, isChat = false) {
    if (avatar == null) return;
    if (!slug) return;

    if (photosSlug !== slug || externalLoadMore) resetPhotos(slug, isChat);

    startFirstPage(slug, isChat);
    openGallery(avatar);
}

function openForMedia(
    slug: string,
    attachments: Attachment[],
    startIndex = 0,
    loadMore?: () => Promise<Attachment[] | null>,
    isChat = false,
) {
    if (!slug || !attachments.length) return;

    resetPhotos(slug, isChat);
    photos = attachments.filter(item => item && item.url);
    externalLoadMore = loadMore ?? null;

    if (!photos.length) return;

    openGallery(photos[0].url, startIndex);
}

function addSlide(attachment: Attachment) {
    const newSlide = toSlide(attachment);
    // Prepend to show the latest uploaded photo first
    slides.unshift(newSlide);
    photos.unshift(attachment);

    if (lightbox?.pswp) {
        refreshGallery(true);
    }
}

function removeSlide(mediaId: number) {
    const index = slides.findIndex(s => s.id === mediaId);
    if (index === -1) return;

    slides.splice(index, 1);
    photos = photos.filter((p: any) => p.id !== mediaId);

    if (slides.length === 0) {
        lightbox?.pswp?.close();
        destroyPhotoSwipe();
        return;
    }

    refreshGallery(true);
    lightbox?.pswp?.goTo(Math.max(0, index - 1));
}

function openGallery(avatar: string, startIndex = 0) {
    destroyPhotoSwipe();

    if (photos.length > 0) {
        usingPlaceholder = false;
        slides = photos.map(toSlide);
    } else {
        usingPlaceholder = true;
        slides = [{
            src: avatar || '/storage/default-profile.png',
            msrc: avatar || '/storage/default-profile.png',
            width: 800,
            height: 800,
            type: 'image',
        }];
    }

    lightbox = new PhotoSwipeLightbox({
        dataSource: slides,
        pswpModule: () => import('photoswipe'),
        appendToEl: document.querySelector('#profile-modal-wrapper') || document.body,
        showHideAnimationType: 'fade',
        showAnimationDuration: 300,
        hideAnimationDuration: 300,
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

    if (props.canDelete) {
        lightbox.on('uiRegister', () => {
            lightbox?.pswp?.ui?.registerElement({
                name: 'delete-button',
                order: 7,
                isButton: true,
                tagName: 'button',
                html: '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 24 24" width="24" height="24"><path fill="#f2f2f2" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>', title: 'Delete photo',
                onClick: (e, el, pswp) => {
                    const currentIndex = pswp.currIndex;
                    const currentSlide = slides[currentIndex];
                    if (currentSlide?.id) {
                        emit('delete', currentSlide.id);
                    }
                },
            });
        });
    }

    lightbox.on('change', () => {
        document.querySelectorAll<HTMLVideoElement>('.pswp__video-container video')
            .forEach(video => video.pause());

        const index = lightbox?.pswp?.currIndex ?? 0;
        if (index >= slides.length - 3) void loadNextPage();
    });

    lightbox.on('close', () => {
        document.querySelectorAll<HTMLVideoElement>('.pswp__video-container video')
            .forEach(video => {
                video.pause();
                video.removeAttribute('src');
                video.load();
            });
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
                container.className = 'dark pswp__thumb-container scrollable-x';
                el.appendChild(container);

                let lastActiveIndex = pswp.currIndex;

                const updateActive = (scrollToActive = true) => {
                    container.querySelectorAll<HTMLElement>('.pswp__thumb-item').forEach((btn, i) => {
                        const active = i === pswp.currIndex;
                        btn.classList.toggle('active', active);

                        if (active && scrollToActive) {
                            container.scrollTo({
                                left: btn.offsetLeft - container.clientWidth / 2 + btn.offsetWidth / 2,
                                behavior: 'smooth',
                            });
                        }
                    });
                };

                syncThumbs = () => {
                    const previousScrollLeft = container.scrollLeft;
                    container.innerHTML = '';

                    slides.forEach((s, index) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'pswp__thumb-item';

                        if (s.msrc) {
                            const img = document.createElement('img');
                            img.src = s.msrc;
                            img.alt = `Thumbnail ${index + 1}`;
                            btn.appendChild(img);
                        } else {
                            btn.classList.add('pswp__thumb-item--video-placeholder');
                            btn.setAttribute('aria-label', `Video ${index + 1}`);
                            btn.textContent = '▶';
                        }

                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            pswp.goTo(index);
                        });

                        container.appendChild(btn);
                    });

                    el.style.display = slides.length > 1 ? '' : 'none';
                    container.scrollLeft = previousScrollLeft;
                    updateActive(false);
                };

                container.addEventListener('scroll', () => {
                    const nearEnd = container.scrollLeft + container.clientWidth >= container.scrollWidth - 120;
                    if (nearEnd) loadNextPage();
                }, { passive: true });

                pswp.on('change', () => {
                    const indexChanged = pswp.currIndex !== lastActiveIndex;
                    lastActiveIndex = pswp.currIndex;
                    updateActive(indexChanged);
                });

                syncThumbs();
            },
        });
    });

    lightbox.on('afterInit', () => {
        isGalleryOpen.value = true;
        registerPhotoSwipeOverlay();
    });

    lightbox.on('destroy', () => {
        isGalleryOpen.value = false;
        unregisterPhotoSwipeOverlay();
        syncThumbs = null;
    });

    lightbox.init();
    lightbox.loadAndOpen(Math.max(0, Math.min(startIndex, slides.length - 1)));
}

function destroyPhotoSwipe() {
    syncThumbs = null;
    isGalleryOpen.value = false;
    unregisterPhotoSwipeOverlay();
    if (lightbox) {
        lightbox.destroy();
        lightbox = null;
    }
}

onUnmounted(() => {
    destroyPhotoSwipe();
});

const galleryOverlayId = createOverlayId('photoswipe');

function registerPhotoSwipeOverlay(): void {
    registerOverlay(galleryOverlayId, 'overlay', () => {
        lightbox?.pswp?.close();
    });
}

function unregisterPhotoSwipeOverlay(): void {
    unregisterOverlay(galleryOverlayId);
}

defineExpose({
    resetPhotos,
    startFirstPage,
    openForProfile,
    openForMedia,
    addSlide,
    removeSlide,
    destroyPhotoSwipe,
    isGalleryOpen,
});
</script>

<template>
    <div class="hidden" aria-hidden="true"></div>
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
    display: flex;
    gap: 8px;
    padding: 8px 12px;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    border-radius: 12px;
    max-width: 90vw;
    overflow-x: auto;
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

.pswp__thumb-item--video-placeholder {
    display: grid;
    place-items: center;
    color: white;
    background: #1f2937;
    font-size: 15px;
}

.pswp__thumb-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>