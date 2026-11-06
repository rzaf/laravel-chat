<!-- src/components/chat/AudioMessage.vue -->
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { Attachment } from '@/types';
import { useAudioStore } from '@/stores/audio';
import { loadPeaks, getCachedPeaks, fallbackPeaks, resamplePeaks } from '@/helpers/waveformPeaks';
import PlayIcon from '@/assets/svgs/Play.svg';
import PauseIcon from '@/assets/svgs/Pause.svg';

const props = defineProps<{
    file: Attachment;
    url?: string;
    isSent?: boolean;
}>();

const audio = useAudioStore();

const root = ref<HTMLElement | null>(null);
const barsEl = ref<HTMLElement | null>(null);

const barCount = ref(36);
const peaks = ref<number[]>(getCachedPeaks(props.file.id) ?? fallbackPeaks(props.file.id));

let ro: ResizeObserver | null = null;
let io: IntersectionObserver | null = null;
let disposed = false;

const isCurrent = computed(() => audio.currentAudio?.id === props.file.id);
const isPlaying = computed(() => isCurrent.value && audio.isPlaying);
const isLoading = computed(() => isCurrent.value && audio.isLoading);

const totalDuration = computed(() =>
    isCurrent.value
        ? audio.duration || props.file.duration || 0
        : props.file.duration || 0,
);

const progress = computed(() => {
    if (!isCurrent.value || !totalDuration.value) return 0;

    return Math.min(1, Math.max(0, audio.currentTime / totalDuration.value));
});

const bars = computed(() =>
    resamplePeaks(peaks.value, barCount.value).map(v =>
        Math.max(14, Math.round(v * 100)),
    ),
);

function fmt(seconds: number) {
    if (!Number.isFinite(seconds) || seconds < 0) return '0:00';

    return `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;
}

const label = computed(() =>
    isCurrent.value ? fmt(audio.currentTime) : fmt(props.file.duration || 0),
);

const totalDurationLabel = computed(() => fmt(totalDuration.value));

function getAttachment(): Attachment {
    return {
        ...props.file,
        url: props.url || props.file.url,
    } as Attachment;
}

function onPlay() {
    audio.playAudio(getAttachment());
}

function seekTo(fraction: number) {
    const duration = totalDuration.value;
    if (!duration) return;

    const position = Math.min(1, Math.max(0, fraction));

    if (isCurrent.value) {
        audio.seek(position * duration);
    } else {
        audio.playAudio(getAttachment(), undefined, position);
    }
}

function onSeek(event: MouseEvent) {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();

    if (!rect.width) return;

    seekTo((event.clientX - rect.left) / rect.width);
}

function onSeekKeydown(event: KeyboardEvent) {
    const duration = totalDuration.value;
    if (!duration) return;

    const step = event.shiftKey ? 15 : 5;
    const current = isCurrent.value ? audio.currentTime : 0;

    let next: number;

    switch (event.key) {
        case 'ArrowLeft':
        case 'ArrowDown':
            next = current - step;
            break;

        case 'ArrowRight':
        case 'ArrowUp':
            next = current + step;
            break;

        case 'Home':
            next = 0;
            break;

        case 'End':
            next = duration;
            break;

        default:
            return;
    }

    event.preventDefault();
    seekTo(next / duration);
}

onMounted(() => {
    if (barsEl.value && typeof ResizeObserver !== 'undefined') {
        ro = new ResizeObserver(([entry]) => {
            if (!entry) return;

            barCount.value = Math.max(
                14,
                Math.min(42, Math.floor(entry.contentRect.width / 6.8)),
            );
        });

        ro.observe(barsEl.value);
    }

    if (getCachedPeaks(props.file.id) || !root.value) return;

    if (typeof IntersectionObserver === 'undefined') {
        loadPeaks(props.file.id, props.url || props.file.url)
            .then(p => {
                if (!disposed) peaks.value = p;
            })
            .catch(error => console.warn('Failed to load audio peaks:', error));

        return;
    }

    io = new IntersectionObserver(
        ([entry]) => {
            if (!entry?.isIntersecting) return;

            io?.disconnect();

            loadPeaks(props.file.id, props.url || props.file.url)
                .then(p => {
                    if (!disposed) peaks.value = p;
                })
                .catch(error => console.warn('Failed to load audio peaks:', error));
        },
        { rootMargin: '200px' },
    );

    io.observe(root.value);
});

onBeforeUnmount(() => {
    disposed = true;
    io?.disconnect();
    ro?.disconnect();
});
</script>

<template>
    <!-- Borderless on purpose: it already lives inside a message bubble -->
    <div ref="root" class="flex items-center w-72 max-w-full gap-3 py-0.5 pr-1 sm:w-80"
        :class="isSent ? 'text-white' : 'text-gray-800 dark:text-gray-100'">
        <!-- Play / Pause -->
        <button type="button" @click.stop="onPlay" :aria-label="isLoading
            ? 'Loading audio'
            : isPlaying
                ? 'Pause'
                : 'Play'
            " :aria-busy="isLoading"
            class="relative grid size-10 shrink-0 cursor-pointer place-items-center rounded-full shadow-md transition active:scale-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
            :class="isSent
                ? 'bg-white text-blue-600 hover:bg-blue-50'
                : 'bg-blue-600 text-white hover:bg-blue-700'
                ">
            <PauseIcon v-if="isPlaying" class="size-5 fill-current" />

            <PlayIcon v-else class="ml-0.5 size-5 fill-current" />

            <span v-if="isLoading" aria-hidden="true" class="absolute inset-0 animate-spin rounded-full border-2"
                :class="isSent
                    ? 'border-blue-600/25 border-t-blue-600'
                    : 'border-white/25 border-t-white'
                    " />
        </button>

        <!-- Waveform & Track Info -->
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <div class="flex items-center justify-between text-xs font-medium opacity-90">
                <span class="truncate text-[13px] leading-tight">
                    {{ file.name || 'Voice message' }}
                </span>
            </div>

            <!-- Waveform -->
            <div ref="barsEl" role="slider" tabindex="0" aria-label="Audio playback position"
                aria-orientation="horizontal" :aria-valuenow="Math.round(progress * 100)" :aria-valuemin="0"
                :aria-valuemax="100" :aria-valuetext="`${label} of ${totalDurationLabel}`"
                class="relative flex h-6 w-full cursor-pointer items-center gap-[2px] rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                @click="onSeek" @keydown="onSeekKeydown">
                <span v-for="(height, index) in bars" :key="index"
                    class="w-full flex-1 rounded-full transition-colors duration-100" :class="index / barCount < progress
                        ? isSent
                            ? 'bg-white'
                            : 'bg-blue-600 dark:bg-blue-400'
                        : isSent
                            ? 'bg-white/40'
                            : 'bg-gray-300 dark:bg-gray-600'
                        " :style="{ height: `${height}%` }" />
            </div>
        </div>

        <!-- Time -->
        <div class="flex min-w-[40px] shrink-0 flex-col items-end justify-center text-[11px] tabular-nums opacity-85">
            <span class="font-semibold">{{ label }}</span>
            <span class="text-[9px] opacity-75">
                / {{ totalDurationLabel }}
            </span>
        </div>
    </div>
</template>