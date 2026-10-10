
<script setup lang="ts">
import { onUnmounted } from 'vue';
import type { Attachment } from '@/types';
import { getProfilePhotos } from '@/api/index.ts';

import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';

type Slide = {
    src: string;
    msrc: string;
    width: number;
    height: number;
};

let photos: Attachment[] = [];
let photosSlug: string | null = null;
let photosPage = 0;
let photosLastPage = 1;
let isFetchingPage = false;
let firstPagePromise: Promise<void> | null = null;
let generation = 0;

let lightbox: PhotoSwipeLightbox | null = null;
let slides: Slide[] = [];
let usingPlaceholder = false;
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

async function fetchPhotosPage(
    slug: string,
    page: number,
): Promise<Attachment[] | null> {
    const gen = generation;
    isFetchingPage = true;

    try {
        const res = await getProfilePhotos(slug, page);

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

/** Starts loading page 1 (once). Safe to call many times. */
function startFirstPage(slug: string): Promise<void> {
    if (firstPagePromise) return firstPagePromise;

    firstPagePromise = fetchPhotosPage(slug, 1).then(atts => {
        if (atts === null) {
            firstPagePromise = null;
            return;
        }

        photos = atts;

        // Replace the placeholder avatar if the gallery is already open.
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

/** Tells PhotoSwipe and the thumbnail bar that slides changed. */
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

/**
 * Called by the parent when the profile avatar is clicked.
 * Starts the first-page request without waiting for it.
 */
function openForProfile(slug: string, avatar: string | null | undefined) {
    if (avatar == null) return;
    if (!slug) return;

    if (photosSlug !== slug) resetPhotos(slug);

    startFirstPage(slug);
    openGallery(avatar);
}

function openGallery(avatar: string) {
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
        }];
    }

    lightbox = new PhotoSwipeLightbox({
        dataSource: slides,
        pswpModule: () => import('photoswipe'),

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

        padding: {
            top: 20,
            bottom: 90,
            left: 20,
            right: 20,
        },
        bgOpacity: 0.9,
    });

    lightbox.on('loadError', ({ content }: any) => {
        console.error(
            '[PhotoSwipe] failed to load:',
            content?.data?.src,
        );
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
                container.className =
                    'dark pswp__thumb-container scrollable-x';

                el.appendChild(container);

                let lastActiveIndex = pswp.currIndex;

                const updateActive = (scrollToActive = true) => {
                    container
                        .querySelectorAll<HTMLElement>('.pswp__thumb-item')
                        .forEach((btn, i) => {
                            const active = i === pswp.currIndex;
                            btn.classList.toggle('active', active);

                            if (active && scrollToActive) {
                                container.scrollTo({
                                    left:
                                        btn.offsetLeft -
                                        container.clientWidth / 2 +
                                        btn.offsetWidth / 2,
                                    behavior: 'smooth',
                                });
                            }
                        });
                };

                // Rebuild the strip from the current slides.
                syncThumbs = () => {
                    const previousScrollLeft = container.scrollLeft;
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
                    container.scrollLeft = previousScrollLeft;
                    updateActive(false);
                };

                // Load the next page near the end of the thumbnail strip.
                container.addEventListener(
                    'scroll',
                    () => {
                        const nearEnd =
                            container.scrollLeft + container.clientWidth >=
                            container.scrollWidth - 120;

                        if (nearEnd) loadNextPage();
                    },
                    { passive: true },
                );

                pswp.on('change', () => {
                    const indexChanged =
                        pswp.currIndex !== lastActiveIndex;

                    lastActiveIndex = pswp.currIndex;
                    updateActive(indexChanged);

                    if (pswp.currIndex >= slides.length - 3) {
                        loadNextPage();
                    }
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

function destroyPhotoSwipe() {
    syncThumbs = null;

    if (lightbox) {
        lightbox.destroy();
        lightbox = null;
    }
}

onUnmounted(() => {
    destroyPhotoSwipe();
});

defineExpose({
    resetPhotos,
    startFirstPage,
    openForProfile,
    destroyPhotoSwipe,
});
</script>

<template>
    <!-- PhotoSwipe renders its gallery outside this component's DOM. -->
    <div id="asdfasdfsdf" class="hidden" aria-hidden="true"></div>
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
