<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import type { Attachment } from '@/types';
import FileIcon from '@/assets/svgs/File.svg';
import { regenerateMediaUrl } from '@/api';
import BlurhashImage from './BlurhashImage.vue';

const props = withDefaults(
    defineProps<{
        attachments: Attachment[];
        isSent?: boolean;
        autoDownload?: boolean;
    }>(),
    {
        autoDownload: true,
    }
);

// Map of attachment ID to loaded active URL or fresh signed URL
const mediaUrls = ref<Record<number, string>>({});
const downloading = ref<Record<number, boolean>>({});
const refreshingUrl = ref<Record<number, boolean>>({});
const observers = ref<Record<number, IntersectionObserver>>({});

const isImage = (type: string) => type.startsWith('image/');
const isVideo = (type: string) => type.startsWith('video/');
const isAudio = (type: string) => type.startsWith('audio/');
// const isFile = (type: string) => (!isImage(type) && !isVideo(type) && !isAudio(type));
const isStreamable = (type: string) => isVideo(type) || isAudio(type);

/**
 * Gets the current active URL for a file (either original signed URL or refreshed one)
 */
function getActiveUrl(file: Attachment): string {
    return mediaUrls.value[file.id] || file.url;
}

/**
 * Refreshes an expired signed URL from Laravel: GET /medias/{id}/url
 */
async function refreshSignedUrl(file: Attachment): Promise<string | null> {
    if (refreshingUrl.value[file.id]) return null;
    refreshingUrl.value[file.id] = true;

    try {
        const data = await regenerateMediaUrl(''+file.id);
        const newUrl = data.data.url;
        console.log('DATA newUrl', newUrl);
        mediaUrls.value[file.id] = newUrl;
        return newUrl;
    } catch (e) {
        console.error(`Failed to refresh signed URL for attachment #${file.id}`, e);
        return null;
    } finally {
        refreshingUrl.value[file.id] = false;
    }
}

/**
 * Triggered automatically if a media element fails to load due to 401/403 (expired signed URL)
 */
async function handleMediaError(file: Attachment) {
    console.warn(`Media failed to load for file #${file.id}. Attempting to refresh signed URL...`);
    await refreshSignedUrl(file);
}

/**
 * Downloads non-streamable files or fetches full blob download
 */
async function loadFile(file: Attachment) {
    if (mediaUrls.value[file.id] && mediaUrls.value[file.id].startsWith('blob:')) return;
    if (downloading.value[file.id]) return;

    // downloading.value[file.id] = true;
    // try {
    //     let currentUrl = getActiveUrl(file);

    //     // Fetch file blob with current signed URL
    //     let response = await fetch(currentUrl);

    //     // If signed URL expired during download action, fetch fresh URL and retry once
    //     if (response.status === 401 || response.status === 403) {
    //         const freshUrl = await refreshSignedUrl(file);
    //         if (freshUrl) {
    //             response = await fetch(freshUrl);
    //         }
    //     }

    //     if (!response.ok) throw new Error('Download failed');

    //     const blob = await response.blob();
    //     mediaUrls.value[file.id] = URL.createObjectURL(blob);
    // } catch (e) {
    //     console.error('Failed to download attachment', e);
    // } finally {
    //     downloading.value[file.id] = false;
    // }
}

function observeAttachment(el: HTMLElement | null, file: Attachment) {
    if (!el) return;

    
    if (props.autoDownload && !isStreamable(file.type)) {
        //             await loadFile(file);
        
        const observer = new IntersectionObserver(
            async ([entry]) => {
                if (entry.isIntersecting) {
                    console.log('interacting with ',file.name);
                    observer.disconnect();
                    await loadFile(file);
                }
            },
            { rootMargin: '200px' }
        );

        observer.observe(el);
        observers.value[file.id] = observer;
    }
}

function manualLoad(file: Attachment) {
    loadFile(file);
}

onUnmounted(() => {
    Object.values(mediaUrls.value).forEach((url) => {
        if (url.startsWith('blob:')) {
            URL.revokeObjectURL(url);
        }
    });
    Object.values(observers.value).forEach(observer => {
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
</script>

<template>
    <div v-if="attachments?.length" class="flex flex-col gap-2 mb-2">
        <div 
            v-for="file in attachments" 
            :key="file.id" 
            class="overflow-hidden rounded-md bg-gray-400 dark:bg-gray-600"
            :ref="(el: any) => observeAttachment(el, file)"
        >
            <!-- 1. STREAMABLE MEDIA (Video & Audio stream natively via signed URL) -->
            <template v-if="isStreamable(file.type)">
                <!-- Video Element -->
                <video 
                    v-if="isVideo(file.type)" 
                    controls 
                    preload="metadata"
                    class="lg:max-w-[500px] sm:max-w-96 w-full rounded-md"
                    @error="handleMediaError(file)"
                >
                    <source :src="getActiveUrl(file)" :type="file.type" />
                </video>

                <!-- Audio Element -->
                <div v-else-if="isAudio(file.type)" class="max-w-full w-96 block my-1">
                    <audio 
                        controls 
                        preload="metadata"
                        class="w-full"
                        @error="handleMediaError(file)"
                    >
                        <source :src="getActiveUrl(file)" :type="file.type" />
                    </audio>
                </div>
                
            </template>

            <!-- 2. DOWNLOADED / FETCHED BLOB MEDIA -->
            <template v-else-if="mediaUrls[file.id] && mediaUrls[file.id].startsWith('blob:')">
                <!-- Image Display -->
                <a v-if="isImage(file.type)" :href="mediaUrls[file.id]" target="_blank" class="block">
                    <img 
                        :src="mediaUrls[file.id]" 
                        :alt="file.name" 
                        class="lg:max-w-[500px] sm:max-w-96 w-64 rounded-md object-contain aspect-auto" 
                        @error="handleMediaError(file)"
                    />
                </a>

                <!-- Generic Downloaded Document -->
                <a 
                    v-else 
                    :href="mediaUrls[file.id]" 
                    :download="file.name" 
                    class="flex items-center gap-3 max-w-96 p-2.5 rounded-lg border" 
                    :class="{
                        'bg-blue-800/40 border-blue-500/50 text-white': isSent,
                        'bg-gray-100 dark:bg-gray-700 border-gray-300 dark:border-gray-500': !isSent
                    }"
                >
                    <div class="p-2 bg-gray-200 dark:bg-gray-600 rounded-md">
                        <FileIcon class="w-6 h-6" />
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="truncate text-sm font-medium">{{ file.name }}</span>
                        <span class="text-xs opacity-70">{{ formatBytes(file.size) }}</span>
                    </div>
                </a>
            </template>

            <!-- 3. PRE-DOWNLOAD PLACEHOLDERS (Always-visible black overlay layer) -->
            <template v-else>
                <!-- Image Placeholder Card -->
                <div 
                    v-if="isImage(file.type)" 
                    class="group relative rounded-md bg-gray-200 dark:bg-gray-700 flex flex-col items-center justify-center lg:w-[500px] sm:w-96 w-full overflow-hidden"
                    :style="{ aspectRatio: `${file.width} / ${file.height}` }"
                    :class="{'animate-pulse': autoDownload && downloading[file.id] }"
                >
                    <!-- <div class="animate-pulse absolute rounded-md w-full h-full bg-gray-100 dark:bg-gray-700 z-8">

                    </div> -->
                    <BlurhashImage 
                        v-if="file.blurhash"
                        :hash="file.blurhash"
                        :src="file.url || ''" 
                        alt="Uploaded media"
                    />

                    
                    <!-- Background Visual Base -->
                    <!-- <div class="text-center p-4 z-20">
                        <div class="mx-auto mb-2 text-gray-600 dark:text-gray-400">
                            <svg class="w-30 h-30  mx-auto" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375 0 11-.75 0 .375 0 01.75 0z" />
                            </svg>
                        </div>
                    </div> -->

                    <!-- ALWAYS VISIBLE BLACK OVERLAY -->
                    <div 
                        v-if="!autoDownload"
                        class="absolute inset-0 bg-black/20 backdrop-blur-xs flex flex-col items-center justify-center text-white z-45 cursor-pointer hover:bg-black/40 transition-colors"
                        @click="manualLoad(file)"
                    >
                        <svg  class="w-8 h-8 mb-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        <span class="text-md text-shadow-2xs font-semibold tracking-wide">Download ({{ formatBytes(file.size) }})</span>
                    </div>

                    <!-- Active Downloading Spinner -->
                    <div v-if="!autoDownload && downloading[file.id]" class="absolute inset-0 bg-black/40 z-50 flex items-center justify-center backdrop-blur-[1px]">
                        <svg class="animate-spin h-8 w-8 text-blue-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </div>

                <!-- Generic File Placeholder Card -->
                <div 
                    v-else 
                    class="group relative flex items-center gap-3 w-52 p-2.5 rounded-lg border-gray-300 dark:border-gray-600 bg-gray-50/50 dark:bg-gray-800/30 overflow-hidden min-h-[58px]"
                    :class="{ 'animate-pulse': downloading[file.id] }"
                >
                    <div class="p-2 bg-gray-200 dark:bg-gray-700 rounded-md relative flex items-center justify-center min-w-10 min-h-10">
                        <FileIcon class="w-6 h-6" />
                    </div>

                    <div class="flex flex-col min-w-0 flex-1">
                        <span class="truncate text-sm font-medium">{{ file.name }}</span>
                        <span class="text-xs opacity-60">{{ formatBytes(file.size) }}</span>
                    </div>

                    <!-- ALWAYS VISIBLE BLACK OVERLAY -->
                    <div 
                        v-if="!downloading[file.id]"
                        class="absolute inset-0 bg-black/75 flex items-center justify-center gap-2 text-white cursor-pointer hover:bg-black/85 transition-colors"
                        @click="manualLoad(file)"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        <span class="text-xs font-semibold">Download ({{ formatBytes(file.size) }})</span>
                    </div>

                    <!-- Active Downloading Spinner -->
                    <!-- <div v-if="downloading[file.id]" class="absolute inset-0 bg-black/40 flex items-center justify-center backdrop-blur-[1px]">
                        <svg class="animate-spin h-6 w-6 text-blue-500" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div> -->
                </div>
            </template>
        </div>
    </div>
</template>




