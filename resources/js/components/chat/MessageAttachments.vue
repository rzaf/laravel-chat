<script setup lang="ts">
import { ref, computed, onUnmounted, onMounted, nextTick } from 'vue';
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';
import type { Attachment } from '@/types';
import FileIcon from '@/assets/svgs/File.svg';
import { regenerateMediaUrl } from '@/api';
import BlurhashImage from './BlurhashImage.vue';
import PhotoIcon from '@/assets/svgs/Photo.svg';
import VideoIcon from '@/assets/svgs/Video.svg';

import DownloadIcon from '@/assets/svgs/Download.svg';

const props = withDefaults(
    defineProps<{
        attachments: Attachment[];
        isSent?: boolean;
        autoDownload?: boolean;
    }>(),
    {
        autoDownload: false,
    }
);

let lightbox: PhotoSwipeLightbox | null = null;
const galleryRef = ref<HTMLElement | null>(null);

const mediaUrls = ref<Record<number, string>>({});
const loaded = ref<Record<number, boolean>>({});
const downloading = ref<Record<number, boolean>>({});
const observers = ref<Record<number, IntersectionObserver>>({});

const isImage = (type: string) => type.startsWith('image/');
const isVideo = (type: string) => type.startsWith('video/');
const isAudio = (type: string) => type.startsWith('audio/');
const isMedia = (file: Attachment) => isImage(file.type) || isVideo(file.type);
const isStreamable = (type: string) => isVideo(type) || isAudio(type);
const isLoaded = (file: Attachment) => !!loaded.value[file.id];

const mediaAttachments = computed(() => props.attachments.filter(isMedia));
const nonMediaAttachments = computed(() => props.attachments.filter((f) => !isMedia(f)));

const visibleMedia = computed(() => mediaAttachments.value.slice(0, 4));
const remainingMediaCount = computed(() => Math.max(0, mediaAttachments.value.length - 4));

const videoThumbs = ref<Record<number, string>>({});
const videoDims = ref<Record<number, { w: number; h: number }>>({});

function makeVideoThumb(file: Attachment, url: string = getActiveUrl(file), retried = false) {
    const video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.preload = 'metadata';
    video.crossOrigin = 'anonymous';

    const cleanup = () => {
        video.onloadedmetadata = video.onseeked = video.onerror = null;
        video.removeAttribute('src');
        video.load();
    };

    video.onloadedmetadata = () => {
        video.currentTime = Math.min(0.1, (video.duration || 0.2) / 2);
    };

    video.onseeked = () => {
        try {
            const scale = Math.min(1, 480 / video.videoWidth);
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            canvas.getContext('2d')!.drawImage(video, 0, 0, canvas.width, canvas.height);
            videoDims.value[file.id] = { w: video.videoWidth, h: video.videoHeight };
            videoThumbs.value[file.id] = canvas.toDataURL('image/jpeg', 0.75);
        } catch (e) {
            console.error('Video thumbnail failed (CORS?)', e);
        } finally {
            cleanup();
        }
    };

    video.onerror = async () => {
        cleanup();
        if (retried) return;
        const fresh = await refreshSignedUrl(file);
        if (fresh) makeVideoThumb(file, fresh, true);
    };

    video.src = url;
}

/**
 * Calculates deterministic bounds for single images (Original size scale)
 */
function singleImageStyle(file: Attachment) {
    if (isVideo(file.type) && (!file.width || !file.height)) {
        const d = videoDims.value[file.id];
        return { width: '100%', height: '280px', aspectRatio: d ? `${d.w} / ${d.h}` : '16 / 9' }; // use fixed height for video because size is unknown
    }
    if (!file.width || !file.height) {
        return { width: '100%', maxWidth: '500px', aspectRatio: '1 / 1' };
    }
    const maxWidth = 500;
    const width = Math.min(Math.max(file.width, 100), maxWidth);
    return {
        width: `${width}px`,
        maxWidth: '100%',
        aspectRatio: `${file.width} / ${file.height}`,
    };
}

function getActiveUrl(file: Attachment): string {
    return mediaUrls.value[file.id] || file.url;
}

const refreshPromises = new Map<number, Promise<string | null>>();

async function refreshSignedUrl(
    file: Attachment,
): Promise<string | null> {
    const existingPromise = refreshPromises.get(file.id);
    if (existingPromise) {
        return existingPromise;
    }
    const promise = (async () => {
        try {
            const data = await regenerateMediaUrl('' + file.id);
            const newUrl = data.data.url;
            mediaUrls.value[file.id] = newUrl;
            return newUrl;
        } catch (e) {
            console.error(
                `Failed to refresh signed URL for attachment #${file.id}`,
                e
            );
            return null;
        }
    })();
    refreshPromises.set(file.id, promise);

    try {
        return await promise;
    } finally {
        if (refreshPromises.get(file.id) === promise) {
            refreshPromises.delete(file.id);
        }
    }
}

async function handleMediaError(
    id: Number,
    event: Event
) {
    const file :Attachment | undefined = props.attachments.find((f) => f.id == id);
    console.log('handleMediaError: ', file);
    if (file == undefined) return;
    const media = event.currentTarget as HTMLAudioElement | HTMLVideoElement;

    const savedTime = media.currentTime;
    const wasPlaying = !media.paused;
    const savedVolume = media.volume;
    const savedMuted = media.muted;
    const savedPlaybackRate = media.playbackRate;

    console.log(
        `[media:${file.id}] URL expired`,
        {
            time: savedTime,
            wasPlaying,
        }
    );

    const newUrl = await refreshSignedUrl(file);

    if (!newUrl) return;

    media.src = newUrl;

    media.volume = savedVolume;
    media.muted = savedMuted;
    media.playbackRate = savedPlaybackRate;

    media.load();

    await new Promise<void>((resolve) => {
        const onMetadata = () => {
            media.removeEventListener('loadedmetadata', onMetadata);
            resolve();
        };

        media.addEventListener('loadedmetadata', onMetadata, {
            once: true,
        });
    });

    try {
        if (Number.isFinite(savedTime) && savedTime >= 0) {
            media.currentTime = savedTime;
        }
    } catch (e) {
        console.warn(
            `[media:${file.id}] Failed to restore currentTime`,
            e
        );
    }

    if (wasPlaying) {
        try {
            await media.play();
        } catch (e) {
            console.warn(
                `[media:${file.id}] Failed to resume playback`,
                e
            );
        }
    }
}

async function loadFile(file: Attachment) {
    if (isLoaded(file)) return;
    if (downloading.value[file.id]) return;

    downloading.value[file.id] = true;
    try {
        let currentUrl = getActiveUrl(file);
        let response = await fetch(currentUrl);

        if (response.status === 401 || response.status === 403) {
            const freshUrl = await refreshSignedUrl(file);
            if (freshUrl) {
                response = await fetch(freshUrl);
            }
        }

        if (!response.ok) throw new Error('Download failed');

        const blob = await response.blob();
        // mediaUrls.value[file.id] = URL.createObjectURL(blob);
        // fix firefox 
        if (isImage(file.type)) {
            const pre = new Image();
            pre.src = getActiveUrl(file);
            try { await pre.decode(); } catch { /* show anyway */ }
        }
        loaded.value[file.id] = true;
    } catch (e) {
        console.error('Failed to download attachment', e);
    } finally {
        downloading.value[file.id] = false;
    }
}

function observeAttachment(el: HTMLElement | null, file: Attachment) {
    if (!el) return;
    if (!props.autoDownload || isStreamable(file.type)) return;

    const observer = new IntersectionObserver(
        async ([entry]) => {
            if (entry.isIntersecting) {
                observer.disconnect();
                await loadFile(file);
            }
        },
        { rootMargin: '200px' }
    );

    observer.observe(el);
    observers.value[file.id] = observer;
}

function manualLoad(file: Attachment) {
    loadFile(file);
}

function initPhotoSwipe() {
    if (!galleryRef.value || mediaAttachments.value.length === 0) return;

    lightbox = new PhotoSwipeLightbox({
        gallery: galleryRef.value,
        children: 'a.pswp-media-item',
        pswpModule: () => import('photoswipe'),
        
        // Animations
        showHideAnimationType: mediaAttachments.value.length > 1 ? 'fade' : 'zoom',
        showAnimationDuration: 450,
        hideAnimationDuration: 250,

        // Zoom levels
        initialZoomLevel: 'fit',            // whole image visible on open
        secondaryZoomLevel: 1.5,            // what double-tap / click toggles to
        maxZoomLevel: 4,                    // allow real pinch/wheel zoom beyond 100%

        // Interaction
        imageClickAction: 'zoom-or-close',  // click zooms, click outside closes
        tapAction: 'toggle-controls',       // single tap on touch toggles UI
        doubleTapAction: 'zoom',
        wheelToZoom: true,                  // ctrl-less mouse wheel zoom on desktop
        pinchToClose: true,
        closeOnVerticalDrag: true,

        // Layout
        padding: { top: 20, bottom: 20, left: 20, right: 20 },
        bgOpacity: 0.9,
        preloadFirstSlide: true,
        preload: [1, 2],
    });

    lightbox.addFilter('domItemData', (itemData, element) => {
        const index = Array.from(galleryRef.value?.querySelectorAll('a.pswp-media-item') || []).indexOf(element);
        const file = mediaAttachments.value[index];

        if (file) {
            if (isVideo(file.type)) {
                itemData.w = Math.max(300, window.innerWidth - 40);
                itemData.h = Math.max(300, window.innerHeight - 40);
                itemData.type = 'video';
            } else {
                itemData.w = file.width || 100;
                itemData.h = file.height || 100;
                itemData.type = 'image';
            }
            itemData.src = getActiveUrl(file);
        }
        return itemData;
    });

    lightbox.on('contentLoad', (e: any) => {
        const { content } = e;
        const index = content.index;
        const file = mediaAttachments.value[index];

        if (file && isImage(file.type)) {
            const markLoaded = () => {
                loaded.value[file.id] = true;
                if (content.data?.src) {
                    mediaUrls.value[file.id] = content.data.src;
                }
            };

            const attachListener = () => {
                const imgEl = (
                    content.element?.tagName === 'IMG'
                        ? content.element
                        : content.element?.querySelector('img')
                ) as HTMLImageElement | null;

                if (!imgEl) return;

                if (imgEl.complete && imgEl.naturalWidth > 0) {
                    markLoaded();
                } else {
                    imgEl.addEventListener('load', markLoaded, { once: true });
                }
            };

            if (content.element) {
                attachListener();
            } else {
                setTimeout(attachListener, 50);
            }
            return;
        }

        if (content.data.type !== 'video') return;
        e.preventDefault();

        const container = document.createElement('div');
        container.className = 'pswp__video-container';
        container.style.cssText = 'display:flex;align-items:center;justify-content:center;background:transparent;';

        const video = document.createElement('video');
        video.controls = true;
        video.playsInline = true;
        video.preload = 'metadata';
        video.style.cssText = 'width:100%;height:100%;object-fit:contain;background:#000;';


        let handling = false;
        video.onerror = async (event) => {
            if (!file) return;
            if (handling) return;
            handling = true;
            
            // Call your existing media fallback handler
            await handleMediaError(file.id, event);
            
            const currentTime = video.currentTime;
            // Update PhotoSwipe data definition with the newly refreshed URL
            const freshUrl = getActiveUrl(file);
            content.data.src = freshUrl;

            video.src = freshUrl;
            // video.load();

            if (lightbox?.pswp?.currSlide?.content === content) {
                video.currentTime = currentTime;
                video.play().catch(() => {});
            }
            handling = false;
        };
        container.appendChild(video);
        content.element = container;
        video.src = content.data.src;

video.addEventListener(
    'loadedmetadata',
    () => {
        if (lightbox?.pswp?.currSlide?.content === content) {
            video.play().catch(() => {});
        }
    },
    { once: true }
);
        // video.play().catch(() => {}); // this line when is added the video double plays and is still played while exiting photoswipe
    });

    // lightbox.on('contentResize', (e: any) => {
    //     if (e.content.data.type !== 'video') return;
    //     e.preventDefault();
    //     const el = e.content.element as HTMLElement | null;
    //     if (el) {
    //         el.style.width = e.width + 'px';
    //         el.style.height = e.height + 'px';
    //     }
    // });

    lightbox.on('change', () => {
        document.querySelectorAll('.pswp__video-container video').forEach((v) => (v as HTMLVideoElement).pause());
    });
    lightbox.on('close', () => {
        document.querySelectorAll('.pswp__video-container video')
            .forEach((el) => {
                const video = el as HTMLVideoElement;
                video.pause();
                video.currentTime = 0;
                video.removeAttribute('src');
                video.load();
                video.remove();
            });
    });

    lightbox.on('loadError', async (event: any) => {
        console.log('photoswipe event loadError', event);
        const { content } = event;
        if (!content || content.data.type === 'video') return;

        const index = content.index;
        const file = mediaAttachments.value[index];
        if (!file) return;

        const freshUrl = await refreshSignedUrl(file);
        if (!freshUrl) return;

        content.data.src = freshUrl;
        content.state = 'empty';
        content.isLoading = false;
        content.isError = false;

        if (content.element) {
            if (content.element.tagName === 'IMG') {
                (content.element as HTMLImageElement).src = freshUrl;
            } else {
                const img = content.element.querySelector('img');
                if (img) img.src = freshUrl;
            }
        }

        content.load(true);

        const pswp = lightbox?.pswp;
        if (pswp) {
            pswp.refreshSlideContent(index);
        }
    });

    lightbox.init();
}

async function handleMediaClick(file: Attachment, index: number) {
    if (isImage(file.type) && !isLoaded(file)) {
        manualLoad(file);
        return;
    }
}

onMounted(() => {
    visibleMedia.value.filter((f) => isVideo(f.type)).forEach((f) => makeVideoThumb(f));
    nextTick(() => {
        initPhotoSwipe();
    });
});

onUnmounted(() => {
    if (lightbox) {
        lightbox.destroy();
        lightbox = null;
    }

    Object.values(mediaUrls.value).forEach((url) => {
        if (url.startsWith('blob:')) {
            URL.revokeObjectURL(url);
        }
    });
    Object.values(observers.value).forEach((observer) => {
        observer.disconnect();
    });
});

function formatBytes(bytes: number): string {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function revealImage(fileId: number, event: Event) {
    const img = event.currentTarget as HTMLImageElement;

    // Make absolutely sure the starting state exists.
    img.classList.add('opacity-0');

    // Wait for the browser to paint the opacity-0 state
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            // Make sure the element is still in the DOM.
            if (!img.isConnected) return;

            img.classList.remove('opacity-0');
            img.classList.add('opacity-100');
        });
    });
}
</script>

<template>
    <div v-if="attachments?.length" class="flex flex-col gap-2 mb-2 max-w-full">
        <!-- ================= MEDIA ATTACHMENTS (SINGLE OR GRID) ================= -->
        <div v-if="mediaAttachments.length > 0" ref="galleryRef" class="w-full">
            <!-- SINGLE MEDIA ITEM -->
            <div v-if="mediaAttachments.length === 1">
                <a v-for="(file, index) in mediaAttachments" :key="file.id" target="_blank"
                    rel="noreferrer"
                    class="pswp-media-item relative overflow-hidden rounded-lg bg-gray-400 dark:bg-gray-600 flex justify-center items-center group cursor-pointer block max-w-full"
                    :style="singleImageStyle(file)" :ref="(el: any) => observeAttachment(el, file)"
                    @click.prevent="handleMediaClick(file, index)">
                    <template v-if="isVideo(file.type)">
                        <img v-if="videoThumbs[file.id]" :src="videoThumbs[file.id]" class="w-full h-full object-cover hover:scale-105 transition-transform duration-200" />
                        <div v-else class="absolute inset-0 bg-gray-500 animate-pulse"></div>
                        <div class="absolute inset-0 z-10 flex items-center justify-center bg-black/25 pointer-events-none">
                            <VideoIcon class="w-10 h-10 text-white drop-shadow" />
                        </div>
                    </template>

                    <template v-else-if="isImage(file.type)">
                        <div v-if="downloading[file.id]" class="" >
                            
                        </div>
                        <div v-if="downloading[file.id]"
                            class="absolute inset-0 z-10 bg-white/25 animate-pulse pointer-events-none"></div>

                        <template v-if="!isLoaded(file)">
                            <BlurhashImage v-if="file.blurhash" class="absolute inset-0 w-full h-full object-cover"
                                :hash="file.blurhash" :src="file.url || ''" />
                            <div v-else
                                class="absolute blur-[2px] inset-0 flex items-center justify-center text-center p-4 z-10">
                                <PhotoIcon class="w-12 h-12 text-gray-600 dark:text-gray-400" />
                            </div>

                            <div v-if="!downloading[file.id]"
                                class="absolute inset-0 bg-black/30 flex items-center justify-center z-20 gap-1.5 text-white cursor-pointer hover:bg-black/45 transition-colors">
                                <DownloadIcon class="w-6 h-6 mb-0.5" />
                                <span class="text-xs font-semibold">Download ({{ formatBytes(file.size) }})</span>
                            </div>
                        </template>

                        <img v-if="isLoaded(file)" :src="getActiveUrl(file)"
                            class="w-full h-full object-cover opacity-0 transition-[opacity,scale] hover:scale-105 duration-200"
                            @load="revealImage(file.id, $event)"
                        />

                        <div v-if="downloading[file.id] && !autoDownload"
                            class="absolute inset-0 bg-black/40 flex items-center justify-center z-20">
                            <svg class="animate-spin h-6 w-6 text-blue-500" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                </path>
                            </svg>
                        </div>
                    </template>
                </a>
            </div>

            <!-- MULTI-MEDIA GRID (>= 2 MEDIA) -->
            <div v-else class="grid gap-0.5 rounded-lg overflow-hidden w-[360px] sm:w-[480px] max-w-full" :class="{
                'grid-cols-2 aspect-[4/3]': mediaAttachments.length === 2,
                'grid-cols-2 grid-rows-2 aspect-square': mediaAttachments.length >= 3,
            }">
                <a v-for="(file, index) in visibleMedia" :key="file.id" target="_blank"
                    rel="noreferrer"
                    class="pswp-media-item relative overflow-hidden bg-gray-700  group cursor-pointer w-full h-full block"
                    :class="[
                        mediaAttachments.length === 3 && index === 0 ? 'row-span-2' : '',
                    ]" :ref="(el: any) => observeAttachment(el, file)" @click.prevent="handleMediaClick(file, index)">
                    <template v-if="isVideo(file.type)">
                        <img v-if="videoThumbs[file.id]" :src="videoThumbs[file.id]" class="w-full h-full object-cover hover:scale-105 transition-transform duration-200" />
                        <div v-else class="absolute inset-0 bg-gray-500 animate-pulse"></div>
                        <div class="absolute inset-0 z-10 flex items-center justify-center bg-black/25 pointer-events-none ">
                            <VideoIcon class="w-10 h-10 text-white drop-shadow " />
                        </div>
                    </template>

                    <template v-else-if="isImage(file.type)">
                        <div v-if="downloading[file.id]"
                            class="absolute inset-0 z-10 bg-white/25 animate-pulse pointer-events-none"></div>

                        <template v-if="!isLoaded(file)">
                            <BlurhashImage v-if="file.blurhash" class="absolute inset-0 w-full h-full object-cover"
                                :hash="file.blurhash" :src="file.url || ''" />
                            <div v-else
                                class="absolute blur-[2px] inset-0 flex items-center justify-center text-center p-4 z-10">
                                <PhotoIcon class="w-10 h-10 text-gray-400" />
                            </div>

                            <div v-if="!downloading[file.id]"
                                class="absolute inset-0 bg-black/40 flex flex-col items-center justify-center z-20 gap-1 text-white hover:bg-black/50 transition-colors p-2 text-center">
                                <DownloadIcon class="w-7 h-7" />
                                <span class="text-xs font-semibold leading-tight">{{ formatBytes(file.size) }}</span>
                            </div>
                        </template>

                        <img v-if="isLoaded(file)" :src="getActiveUrl(file)"
                            class="w-full h-full object-cover opacity-0 transition-[opacity,scale] hover:scale-105 duration-200 "
                            @load="revealImage(file.id, $event)"
                        />

                        <div v-if="downloading[file.id] && !autoDownload"
                            class="absolute inset-0 bg-black/40 flex items-center justify-center z-20">
                            <svg class="animate-spin h-6 w-6 text-blue-500" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                </path>
                            </svg>
                        </div>

                        <div v-if="index === 3 && remainingMediaCount > 0"
                            class="absolute inset-0 bg-black/60 backdrop-blur-[2px] flex items-center justify-center text-white font-bold text-2xl z-30 pointer-events-none">
                            +{{ remainingMediaCount }}
                        </div>
                    </template>
                </a>

                <a v-for="file in mediaAttachments.slice(4)" :key="`hidden-${file.id}`"
                    class="pswp-media-item hidden"></a>
            </div>
        </div>

        <!-- ================= NON-MEDIA ATTACHMENTS (DOCS/AUDIO) ================= -->
        <div v-if="nonMediaAttachments.length > 0" class="flex flex-col gap-2">
            <div v-for="file in nonMediaAttachments" :key="file.id"
                class="overflow-hidden rounded-md flex justify-center align-middle"
                :ref="(el: any) => observeAttachment(el, file)">
                <div v-if="isAudio(file.type)" class="max-w-full w-96 block my-1">
                    <audio controls preload="metadata" class="w-full" 
                        @error="handleMediaError(file.id, $event)" 
                        :src="getActiveUrl(file)"
                        :type="file.type" 
                    >
                    </audio>
                </div>

                <a v-else-if="isLoaded(file)" :href="getActiveUrl(file)" :download="file.name"
                    class="flex items-center gap-3 max-w-96 p-2.5 rounded-lg border w-full" :class="{
                        'bg-blue-800/40 border-blue-500/50 text-white': isSent,
                        'bg-gray-100 dark:bg-gray-700 border-gray-300 dark:border-gray-500': !isSent,
                    }">
                    <div class="p-2 bg-gray-200 dark:bg-gray-600 rounded-md">
                        <FileIcon class="w-6 h-6" />
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="truncate text-sm font-medium">{{ file.name }}</span>
                        <span class="text-xs opacity-70">{{ formatBytes(file.size) }}</span>
                    </div>
                </a>

                <div v-else
                    class="group relative flex items-center gap-3 w-52 p-2.5 rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50/50 dark:bg-gray-800/30 overflow-hidden min-h-[58px]"
                    :class="{ 'animate-pulse': downloading[file.id] }">
                    <div
                        class="p-2 bg-gray-200 dark:bg-gray-700 rounded-md relative flex items-center justify-center min-w-10 min-h-10">
                        <FileIcon class="w-6 h-6" />
                    </div>

                    <div class="flex flex-col min-w-0 flex-1">
                        <span class="truncate text-sm font-medium">{{ file.name }}</span>
                        <span class="text-xs opacity-60">{{ formatBytes(file.size) }}</span>
                    </div>

                    <div v-if="!downloading[file.id]"
                        class="absolute inset-0 bg-black/75 flex items-center justify-center gap-2 text-white cursor-pointer hover:bg-black/85 transition-colors"
                        @click="manualLoad(file)">
                        <DownloadIcon class="w-8 h-8 mb-1" />
                        <span class="text-xs font-semibold">Download ({{ formatBytes(file.size) }})</span>
                    </div>

                    <div v-if="downloading[file.id]"
                        class="absolute inset-0 bg-black/40 flex items-center justify-center">
                        <svg class="animate-spin h-6 w-6 text-blue-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>