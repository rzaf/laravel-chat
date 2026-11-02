<!-- src/components/chat/GlobalAudioPlayer.vue -->
<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import WaveSurfer from 'wavesurfer.js';
import { useAudioStore } from '@/stores/audio';

import PlayIcon from '@/assets/svgs/Play.svg';
import PauseIcon from '@/assets/svgs/Pause.svg';
import CloseIcon from '@/assets/svgs/Close.svg';
import Volume1Icon from '@/assets/svgs/Volume1.svg';
import Volume2Icon from '@/assets/svgs/Volume2.svg';
import VolumeXIcon from '@/assets/svgs/VolumeX.svg';

const audio = useAudioStore();

const waveRef = ref<HTMLElement | null>(null);
const volumeButtonRef = ref<HTMLButtonElement | null>(null);

let ws: WaveSurfer | null = null;
let currentLoadedAudioId: number | string | null = null;
let currentMedia: HTMLMediaElement | null = null;
let currentLoadedPeaks: typeof audio.peaks | null = null;
let currentLoadedDuration: number | undefined;
let themeObserver: MutationObserver | null = null;

// -----------------------------------------------------------------------------
// Computed state
// -----------------------------------------------------------------------------

const title = computed(() => audio.currentAudio?.name || 'Voice message');

const speedLabel = computed(() => `${audio.playbackRate}×`);

const playbackDuration = computed(() => {
    const candidates = [
        audio.duration,
        audio.currentAudio?.duration,
        audio.audioElement?.duration,
    ];

    return candidates.find(
        (value): value is number =>
            typeof value === 'number' &&
            Number.isFinite(value) &&
            value > 0,
    ) ?? 0;
});

const currentTime = computed(() => {
    return Number.isFinite(audio.currentTime)
        ? Math.max(0, audio.currentTime)
        : 0;
});

// -----------------------------------------------------------------------------
// Formatting
// -----------------------------------------------------------------------------

function formatTime(value: number): string {
    if (!Number.isFinite(value) || value < 0) return '0:00';

    const total = Math.floor(value);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;

    const formattedSeconds = String(seconds).padStart(2, '0');

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${formattedSeconds}`;
    }

    return `${minutes}:${formattedSeconds}`;
}

// -----------------------------------------------------------------------------
// Theme
// -----------------------------------------------------------------------------

function isDark(): boolean {
    return typeof document !== 'undefined' &&
        document.documentElement.classList.contains('dark');
}

function palette() {
    return isDark()
        ? {
            wave: 'rgba(148, 163, 184, 0.40)',
            progress: '#60a5fa',
        }
        : {
            wave: 'rgba(100, 116, 139, 0.38)',
            progress: '#2563eb',
        };
}

// -----------------------------------------------------------------------------
// WaveSurfer
// -----------------------------------------------------------------------------

function destroyWave() {
    ws?.destroy();

    ws = null;
    currentLoadedAudioId = null;
    currentMedia = null;
    currentLoadedPeaks = null;
    currentLoadedDuration = undefined;
}

function buildWave() {
    const media = audio.audioElement;
    const audioId = audio.currentAudio?.id;

    if (!media || !waveRef.value || audioId == null) {
        destroyWave();
        return;
    }

    const duration = [
        audio.duration,
        audio.currentAudio?.duration,
        media.duration,
    ].find(
        (value): value is number =>
            typeof value === 'number' &&
            Number.isFinite(value) &&
            value > 0,
    );

    const peaks = audio.peaks;

    // Avoid unnecessarily recreating the waveform.
    if (
        ws &&
        currentLoadedAudioId === audioId &&
        currentMedia === media &&
        currentLoadedPeaks === peaks &&
        currentLoadedDuration === duration
    ) {
        return;
    }

    destroyWave();

    const { wave, progress } = palette();

    try {
        ws = WaveSurfer.create({
            container: waveRef.value,
            media,
            peaks: peaks.length ? [peaks] : undefined,
            duration,
            height: 40,
            barWidth: 3,
            barGap: 2,
            barRadius: 2,

            cursorWidth: 0,
            waveColor: wave,
            progressColor: progress,

            dragToSeek: true,
            normalize: true,
        });

        currentLoadedAudioId = audioId;
        currentMedia = media;
        currentLoadedPeaks = peaks;
        currentLoadedDuration = duration;

        ws.on('error', (error) => {
            console.error('[GlobalAudioPlayer] WaveSurfer error:', error);
        });
    } catch (error) {
        destroyWave();
        console.error('[GlobalAudioPlayer] Failed to initialize WaveSurfer:', error);
    }
}

// Rebuild when the audio element, selected audio, peaks, or duration changes.
watch(
    () => [
        audio.audioElement,
        audio.currentAudio?.id,
        audio.peaks,
        audio.duration,
        audio.currentAudio?.duration,
    ] as const,
    async () => {
        await nextTick();
        buildWave();
    },
    { flush: 'post' },
);

// -----------------------------------------------------------------------------
// Waveform keyboard controls
// -----------------------------------------------------------------------------

function handleWaveKeydown(event: KeyboardEvent) {
    const media = audio.audioElement;
    const duration = playbackDuration.value;

    if (!media || duration <= 0) return;

    const step = event.shiftKey ? 15 : 5;
    let nextTime: number;

    switch (event.key) {
        case 'ArrowLeft':
        case 'ArrowDown':
            nextTime = currentTime.value - step;
            break;

        case 'ArrowRight':
        case 'ArrowUp':
            nextTime = currentTime.value + step;
            break;

        case 'Home':
            nextTime = 0;
            break;

        case 'End':
            nextTime = duration;
            break;

        default:
            return;
    }

    event.preventDefault();

    media.currentTime = Math.max(0, Math.min(duration, nextTime));
}

// -----------------------------------------------------------------------------
// Volume controls
// -----------------------------------------------------------------------------

const volume = ref(1);
const isMuted = ref(false);
const showVolumePopup = ref(false);

let previousVolume = 1;

function toggleVolumePopup() {
    showVolumePopup.value = !showVolumePopup.value;
}

function applyVolume(value: number) {
    const next = Math.max(0, Math.min(1, value));

    if (next > 0) {
        previousVolume = next;
    }

    volume.value = next;
    isMuted.value = next === 0;

    audio.setVolume(next);
}

function handleVolumeChange(event: Event) {
    const value = Number((event.target as HTMLInputElement).value);
    applyVolume(value);
}

function toggleMute() {
    if (isMuted.value) {
        applyVolume(previousVolume || 1);
    } else {
        applyVolume(0);
    }
}

function handleClickOutside(event: MouseEvent) {
    const target = event.target;

    if (target instanceof Element && target.closest('.volume-container')) {
        return;
    }

    showVolumePopup.value = false;
}

function handleDocumentKeydown(event: KeyboardEvent) {
    if (event.key !== 'Escape' || !showVolumePopup.value) return;

    showVolumePopup.value = false;
    volumeButtonRef.value?.focus();
}

// -----------------------------------------------------------------------------
// Lifecycle
// -----------------------------------------------------------------------------

onMounted(async () => {
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', handleDocumentKeydown);

    themeObserver = new MutationObserver(() => {
        ws?.setOptions(palette());
    });

    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });

    await nextTick();
    buildWave();
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleClickOutside);
    document.removeEventListener('keydown', handleDocumentKeydown);

    themeObserver?.disconnect();
    themeObserver = null;

    destroyWave();
});
</script>

<template>
    <Transition name="dock">
        <section v-if="audio.currentAudio" role="region" aria-label="Audio player"
            class="relative z-40 flex h-[4.5rem] shrink-0 items-center gap-3 border-t border-gray-200 bg-white/95 px-3 shadow-lg backdrop-blur-xl dark:border-gray-700 dark:bg-gray-800/95 sm:gap-4 sm:px-4">
            <!-- Play / Pause -->
            <button type="button" @click="audio.togglePlay()" :aria-label="audio.isPlaying ? 'Pause' : 'Play'"
                class="relative grid size-11 shrink-0 cursor-pointer place-items-center rounded-full bg-blue-600 text-white shadow-sm transition-colors hover:bg-blue-700 active:scale-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500">
                <span v-if="audio.isLoading && audio.isPlaying" aria-hidden="true"
                    class="absolute inset-0 animate-spin rounded-full border-2 border-white/30 border-t-white" />

                <PauseIcon v-if="audio.isPlaying" class="size-4 fill-white" />

                <PlayIcon v-else class="ml-0.5 size-4 fill-white" />
            </button>

            <!-- Title & Time -->
            <div class="hidden w-32 shrink-0 flex-col md:flex md:w-44">
                <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ title }}
                </span>

                <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                    {{ formatTime(currentTime) }}
                    <span class="opacity-60">
                        / {{ formatTime(playbackDuration) }}
                    </span>
                </span>
            </div>

            <!-- Waveform -->
            <div ref="waveRef" role="slider" tabindex="0" aria-label="Audio playback position"
                aria-orientation="horizontal" :aria-valuemin="0" :aria-valuemax="playbackDuration"
                :aria-valuenow="Math.min(currentTime, playbackDuration)"
                :aria-valuetext="`${formatTime(currentTime)} of ${formatTime(playbackDuration)}`"
                @keydown="handleWaveKeydown"
                class="h-10 min-w-0 flex-1 cursor-pointer rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-800" />

            <!-- Mobile Time -->
            <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400 md:hidden">
                {{ formatTime(currentTime) }}
                <span class="opacity-60">/ {{ formatTime(playbackDuration) }}</span>
            </span>

            <!-- Right Controls -->
            <div class="flex shrink-0 items-center gap-2 border-l border-gray-200 pl-2 dark:border-gray-700">

                <!-- Playback Speed -->
                <button type="button" @click="audio.cycleSpeed()" :aria-label="`Playback speed: ${speedLabel}`"
                    class="min-w-9 cursor-pointer rounded-lg px-1.5 py-1 text-xs font-semibold tabular-nums text-gray-600 transition-colors hover:bg-gray-200/70 focus-visible:outline-2 focus-visible:outline-blue-500 dark:text-gray-300 dark:hover:bg-gray-700">
                    {{ speedLabel }}
                </button>

                <!-- Volume -->
                <div
                    class="volume-container relative flex items-center rounded-lg bg-gray-100/70 p-1 dark:bg-gray-900/40">

                    <!-- Volume Popup Trigger -->
                    <button ref="volumeButtonRef" type="button" @click="toggleVolumePopup" aria-label="Volume controls"
                        aria-controls="global-audio-volume-popup" :aria-expanded="showVolumePopup"
                        class="grid size-8 cursor-pointer place-items-center rounded-md text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-700 focus-visible:outline-2 focus-visible:outline-blue-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200">
                        <VolumeXIcon v-if="isMuted" class="size-4" />

                        <Volume1Icon v-else-if="volume < 0.9" class="size-4" />

                        <Volume2Icon v-else class="size-4" />
                    </button>

                    <!-- Volume Popup -->
                    <Transition name="volume">
                        <div v-if="showVolumePopup" id="global-audio-volume-popup" role="group"
                            aria-label="Volume settings"
                            class="absolute bottom-full left-1/2 z-50 mb-3 flex -translate-x-1/2 flex-col items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-3.5 shadow-xl dark:border-gray-700 dark:bg-gray-800">
                            <!-- Mute -->
                            <button type="button" @click="toggleMute" :aria-label="isMuted ? 'Unmute' : 'Mute'"
                                :aria-pressed="isMuted"
                                class="grid size-8 cursor-pointer place-items-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus-visible:outline-2 focus-visible:outline-blue-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100">
                                <VolumeXIcon v-if="isMuted" class="size-4" />

                                <Volume2Icon v-else class="size-4" />
                            </button>

                            <!-- Vertical Slider -->
                            <input type="range" min="0" max="1" step="0.05" :value="volume"
                                :aria-valuetext="`${Math.round(volume * 100)}%`" aria-label="Volume"
                                @input="handleVolumeChange"
                                class="h-24 w-1.5 cursor-pointer appearance-none rounded-lg bg-gray-300 accent-blue-600 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-500 dark:bg-gray-700 dark:accent-blue-400"
                                style="writing-mode: vertical-lr; direction: rtl;" />

                            <span class="text-[10px] font-medium tabular-nums text-gray-500 dark:text-gray-400">
                                {{ Math.round(volume * 100) }}%
                            </span>
                        </div>
                    </Transition>
                </div>

                <!-- Stop / Close -->
                <button type="button" @click="audio.stop()" aria-label="Stop playback and close player"
                    class="grid size-8 cursor-pointer place-items-center rounded-lg text-gray-500 transition-colors hover:bg-gray-200/70 hover:text-gray-800 focus-visible:outline-2 focus-visible:outline-blue-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100">
                    <CloseIcon class="size-4" />
                </button>
            </div>
        </section>
    </Transition>
</template>

<style scoped>
.dock-enter-active,
.dock-leave-active {
    transition:
        opacity 0.18s ease,
        transform 0.18s ease;
}

.dock-enter-from,
.dock-leave-to {
    opacity: 0;
    transform: translateY(8px);
}

.volume-enter-active,
.volume-leave-active {
    transition:
        opacity 0.15s ease,
        transform 0.15s ease;
}

.volume-enter-from,
.volume-leave-to {
    opacity: 0;
    transform: translateY(4px) scale(0.98);
}

@media (prefers-reduced-motion: reduce) {

    .dock-enter-active,
    .dock-leave-active,
    .volume-enter-active,
    .volume-leave-active {
        transition: none;
    }
}
</style>