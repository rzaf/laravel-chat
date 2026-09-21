<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import type { Attachment } from '@/types';
import FileIcon from '@/assets/svgs/File.svg';
import { regenerateMediaUrl } from '@/api';
import BlurhashImage from './BlurhashImage.vue';
import PhotoIcon from '@/assets/svgs/Photo.svg';
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

// Map of attachment ID to loaded active URL or fresh signed URL
const mediaUrls = ref<Record<number, string>>({});
const downloading = ref<Record<number, boolean>>({});
const refreshingUrl = ref<Record<number, boolean>>({});
const observers = ref<Record<number, IntersectionObserver>>({});

const isImage = (type: string) => type.startsWith('image/');
const isVideo = (type: string) => type.startsWith('video/');
const isAudio = (type: string) => type.startsWith('audio/');
const isStreamable = (type: string) => isVideo(type) || isAudio(type);
const isBlobLoaded = (file: Attachment) => !!mediaUrls.value[file.id]?.startsWith('blob:');

/**
 * Single discriminant used by the template instead of nested v-if/v-else-if chains.
 * Collapses "is it streamable / already-downloaded / placeholder" AND
 * "is it an image / not" into one flat value per file.
 */
// type AttachmentKind = 'video' | 'audio' | 'blob-image' | 'blob-file' | 'placeholder-image' | 'placeholder-file';

// function getKind(file: Attachment): AttachmentKind {
//     if (isVideo(file.type)) return 'video';
//     if (isAudio(file.type)) return 'audio';
//     if (isBlobLoaded(file)) return isImage(file.type) ? 'blob-image' : 'blob-file';
//     return isImage(file.type) ? 'placeholder-image' : 'placeholder-file';
// }

// function imageContainerStyle(file: Attachment) {
//     return {
//         width: `${Math.min(file.width || 0, 500)}px`,
//         aspectRatio: `${file.width} / ${file.height}`,
//     };
// }

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
        const data = await regenerateMediaUrl('' + file.id);
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
    if (isBlobLoaded(file)) return;
    if (downloading.value[file.id]) return;

    downloading.value[file.id] = true;
    try {
        let currentUrl = getActiveUrl(file);

        // Fetch file blob with current signed URL
        let response = await fetch(currentUrl);

        // If signed URL expired during download action, fetch fresh URL and retry once
        if (response.status === 401 || response.status === 403) {
            const freshUrl = await refreshSignedUrl(file);
            if (freshUrl) {
                response = await fetch(freshUrl);
            }
        }

        if (!response.ok) throw new Error('Download failed');

        const blob = await response.blob();
        mediaUrls.value[file.id] = URL.createObjectURL(blob);
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
                console.log('interacting with ', file.name);
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

onUnmounted(() => {
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
</script>

<template>
    <div v-if="attachments?.length" class="flex flex-col gap-2 mb-2">
        <div v-for="file in attachments" :key="file.id"
            class="overflow-hidden rounded-md bg-gray-400 dark:bg-gray-600 flex justify-center align-middle"
            :ref="(el: any) => observeAttachment(el, file)">

            <!-- Video -->
            <video v-if="isVideo(file.type)" controls preload="metadata"
                class="lg:max-w-[500px] sm:max-w-96 w-full rounded-md" @error="handleMediaError(file)">
                <source :src="getActiveUrl(file)" :type="file.type" />
            </video>

            <!-- Audio -->
            <div v-else-if="isAudio(file.type)" class="max-w-full w-96 block my-1">
                <audio controls preload="metadata" class="w-full" @error="handleMediaError(file)">
                    <source :src="getActiveUrl(file)" :type="file.type" />
                </audio>
            </div>

            <!-- image (blob) -->
            <div v-else-if="isImage(file.type)" class="relative overflow-hidden rounded-md min-w-44 aspect-square"
                :style="{
                        width: `${Math.min(file.width || 0, 500)}px`,
                        aspectRatio: `${file.width} / ${file.height}`,
                    }"
                >

                <div v-if="downloading[file.id]"
                    class="absolute inset-0 z-10 bg-white/15 animate-pulse pointer-events-none"></div>

                <template v-if="!isBlobLoaded(file)">
                    <BlurhashImage v-if="file.blurhash" class="absolute inset-0" :hash="file.blurhash"
                        :src="file.url || ''" />
                    <div v-else
                        class="absolute blur-[2px] inset-0 flex items-center justify-center text-center p-4 z-10">
                        <div class="mx-auto mb-2 text-gray-600 dark:text-gray-400">
                            <PhotoIcon class="w-30 h-30 mx-auto" />
                        </div>
                    </div>
                </template>

                <img v-if="isBlobLoaded(file)" :src="mediaUrls[file.id]"
                    class="absolute inset-0 w-full h-full object-contain opacity-0 transition-opacity duration-200"
                    @load="(e) => e.currentTarget.classList.remove('opacity-0')" />

                <template v-if="!isBlobLoaded(file)">
                    <!-- BLACK OVERLAY -->
                    <div v-if="!downloading[file.id]"
                        class="absolute inset-0 bg-black/25 flex items-center justify-center z-20 gap-2 text-white cursor-pointer hover:bg-black/35 transition-colors"
                        @click="manualLoad(file)">
                        <DownloadIcon class="w-8 h-8 mb-1" />
                        <span class="text-xs text-shadow-md font-semibold">Download ({{ formatBytes(file.size)
                            }})</span>
                    </div>

                    <!-- Active Downloading Spinner -->
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
                </template>
            </div>

            <!-- Downloaded generic document (blob) -->
            <a v-else-if="isBlobLoaded(file)" :href="mediaUrls[file.id]" :download="file.name"
                class="flex items-center gap-3 max-w-96 p-2.5 rounded-lg border" :class="{
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

            <!-- Generic file placeholder card (not yet downloaded) -->
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

                <!-- BLACK OVERLAY -->
                <div v-if="!downloading[file.id]"
                    class="absolute inset-0 bg-black/75 flex items-center justify-center gap-2 text-white cursor-pointer hover:bg-black/85 transition-colors"
                    @click="manualLoad(file)">
                    <DownloadIcon class="w-8 h-8 mb-1" />
                    <span class="text-xs font-semibold">Download ({{ formatBytes(file.size) }})</span>
                </div>

                <!-- Active Downloading Spinner -->
                <div v-if="downloading[file.id]" class="absolute inset-0 bg-black/40 flex items-center justify-center">
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
</template>