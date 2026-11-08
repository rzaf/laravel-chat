import { defineStore } from 'pinia';
import { ref, shallowRef } from 'vue';
import type { Attachment } from '@/types';
import { regenerateMediaUrl } from '@/api';
import { loadPeaks, getCachedPeaks, fallbackPeaks } from '@/helpers/waveformPeaks';

export const SPEEDS = [1, 1.5, 2] as const;

// Decoding a whole file just to draw a waveform is wasteful for long tracks
// (e.g. music with cover art). Longer than this -> use the cheap fallback bars.
export const MAX_PEAKS_DURATION = 15 * 60;

export const useAudioStore = defineStore('audio', () => {
    const currentAudio = ref<Attachment | null>(null);
    const currentChatSlug = ref<string | null>(null);
    const isPlaying = ref(false);
    const isLoading = ref(false);
    const currentTime = ref(0);
    const duration = ref(0);
    const playbackRate = ref<number>(1);
    const volume = ref<number>(1);
    const peaks = ref<number[]>([]);

    // shallowRef: never wrap a DOM element in a deep reactive proxy
    const audioElement = shallowRef<HTMLAudioElement | null>(null);

    let teardown: (() => void) | null = null;
    let playbackRequested = false;

    // ---- shared play() failure handling (was copy-pasted 3 times) ----
    function handlePlayFailure(el: HTMLAudioElement, error: unknown) {
        if (audioElement.value !== el) return;

        console.error('Audio playback failed:', error);
        isPlaying.value = false;

        // Browser autoplay restrictions require another user interaction.
        if ((error as DOMException)?.name === 'NotAllowedError') {
            playbackRequested = false;
            isLoading.value = false;
        }
    }

    // ---- OS media controls (lock screen, headset buttons, browser media hub) ----
    function setMediaSession(audio: Attachment) {
        if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) return;

        navigator.mediaSession.metadata = new MediaMetadata({
            title: audio.name || 'Voice message',
            artwork: audio.thumbnail_url
                ? [{ src: audio.thumbnail_url, type: 'image/jpeg' }]
                : [],
        });

        const handlers: [MediaSessionAction, MediaSessionActionHandler][] = [
            ['play', () => { if (!playbackRequested) togglePlay(); }],
            ['pause', () => { if (playbackRequested) togglePlay(); }],
            ['seekbackward', (d) => skip(-(d.seekOffset ?? 10))],
            ['seekforward', (d) => skip(d.seekOffset ?? 10)],
            ['seekto', (d) => { if (d.seekTime != null) seek(d.seekTime); }],
            ['stop', () => stop()],
        ];
        for (const [action, handler] of handlers) {
            try {
                navigator.mediaSession.setActionHandler(action, handler);
            } catch {
                /* action not supported by this browser */
            }
        }
    }

    function clearMediaSession() {
        if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) return;

        navigator.mediaSession.metadata = null;
        navigator.mediaSession.playbackState = 'none';
        for (const action of ['play', 'pause', 'seekbackward', 'seekforward', 'seekto', 'stop'] as MediaSessionAction[]) {
            try {
                navigator.mediaSession.setActionHandler(action, null);
            } catch {
                /* ignore */
            }
        }
    }

    function setPlaybackState(state: MediaSessionPlaybackState) {
        if (typeof navigator !== 'undefined' && 'mediaSession' in navigator) {
            navigator.mediaSession.playbackState = state;
        }
    }

    function updatePositionState(el: HTMLAudioElement) {
        if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) return;
        if (!Number.isFinite(duration.value) || duration.value <= 0) return;

        try {
            navigator.mediaSession.setPositionState({
                duration: duration.value,
                playbackRate: el.playbackRate || 1,
                position: Math.min(Math.max(0, el.currentTime), duration.value),
            });
        } catch {
            /* invalid state, ignore */
        }
    }

    function detach() {
        playbackRequested = false;

        teardown?.();
        teardown = null;

        if (audioElement.value) {
            audioElement.value.pause();
            audioElement.value.removeAttribute('src');
            audioElement.value.load();
        }

        audioElement.value = null;
    }


    function bind(el: HTMLAudioElement, audio: Attachment, startAt: number) {
        let retried = false;
        let pendingStartAt = startAt;

        const on = <K extends keyof HTMLMediaElementEventMap>(
            type: K,
            fn: (e: HTMLMediaElementEventMap[K]) => void,
        ) => {
            el.addEventListener(type, fn);
            return () => el.removeEventListener(type, fn);
        };

        const onPlayFailure = (error: unknown) => handlePlayFailure(el, error);

        const offs = [
            on('timeupdate', () => {
                currentTime.value = el.currentTime;
                updatePositionState(el);
            }),

            on('loadedmetadata', () => {
                if (Number.isFinite(el.duration)) {
                    duration.value = el.duration;
                }

                updatePositionState(el);

                if (pendingStartAt > 0 && Number.isFinite(el.duration)) {
                    el.currentTime = Math.min(pendingStartAt, el.duration);
                    currentTime.value = el.currentTime;
                    pendingStartAt = 0;
                }
            }),

            on('play', () => {
                playbackRequested = true;
                isPlaying.value = true;
                isLoading.value = false;
                setPlaybackState('playing');
            }),

            on('pause', () => {
                isPlaying.value = false;
                setPlaybackState('paused');
            }),

            on('waiting', () => {
                isLoading.value = true;
            }),

            on('canplay', () => {
                isLoading.value = false;
            }),

            on('ended', () => {
                playbackRequested = false;
                isPlaying.value = false;
                currentTime.value = 0;
                el.currentTime = 0;
            }),

            on('error', async () => {
                if (audioElement.value !== el) return;

                // The refreshed URL also failed. Allow a future user retry.
                if (retried) {
                    retried = false;
                    playbackRequested = false;
                    isPlaying.value = false;
                    isLoading.value = false;
                    return;
                }

                retried = true;
                isLoading.value = true;

                const resumeAt = Math.max(
                    Number.isFinite(el.currentTime) ? el.currentTime : 0,
                    Number.isFinite(currentTime.value) ? currentTime.value : 0,
                    pendingStartAt || 0,
                );

                try {
                    const { data } = await regenerateMediaUrl(String(audio.id));

                    // The user may have switched to another audio while refreshing.
                    if (audioElement.value !== el) return;

                    if (!data?.url) {
                        throw new Error('URL refresh returned an empty URL');
                    }

                    // Keep the refreshed URL in the current audio state.
                    if (currentAudio.value?.id === audio.id) {
                        currentAudio.value = {
                            ...currentAudio.value,
                            url: data.url,
                        };
                    }

                    const restorePlayback = () => {
                        if (audioElement.value !== el) return;

                        const maxTime = Number.isFinite(el.duration)
                            ? el.duration
                            : resumeAt;

                        el.currentTime = Math.min(resumeAt, maxTime);
                        currentTime.value = el.currentTime;
                        pendingStartAt = 0;

                        el.playbackRate = playbackRate.value;
                        el.volume = volume.value;

                        // Respect the user's latest play/pause action.
                        if (!playbackRequested) {
                            isLoading.value = false;
                            return;
                        }

                        // Resume automatically after the signed URL is refreshed.
                        el.play().catch(onPlayFailure);
                    };

                    el.addEventListener('loadedmetadata', restorePlayback, {
                        once: true,
                    });

                    el.src = data.url;
                    el.load();
                } catch (error) {
                    console.error('Audio URL refresh failed:', error);

                    if (audioElement.value === el) {
                        retried = false;
                        playbackRequested = false;
                        isPlaying.value = false;
                        isLoading.value = false;
                    }
                }
            }),
        ];

        teardown = () => offs.forEach(off => off());
    }


    function playAudio(audio: Attachment, chatSlug?: string, startFraction = 0) {
        if (currentAudio.value?.id === audio.id && audioElement.value) {
            togglePlay();
            return;
        }

        detach();
        playbackRequested = true;

        currentAudio.value = audio;
        setMediaSession(audio);
        currentChatSlug.value = chatSlug ?? currentChatSlug.value;
        currentTime.value = 0;
        duration.value = audio.duration || 0;
        isLoading.value = true;

        peaks.value = getCachedPeaks(audio.id) ?? fallbackPeaks(audio.id, 512);
        if (!audio.duration || audio.duration <= MAX_PEAKS_DURATION) {
            loadPeaks(audio.id, audio.url)
                .then((p) => {
                    if (currentAudio.value?.id === audio.id) peaks.value = p;
                })
                .catch((error) => console.warn('Failed to load audio peaks:', error));
        }

        const el = new Audio(audio.url);
        el.preload = 'auto';
        el.playbackRate = playbackRate.value;
        el.volume = volume.value;
        audioElement.value = el;
        bind(el, audio, startFraction * (audio.duration || 0));

        el.play().catch((error) => handlePlayFailure(el, error));
    }

    function togglePlay() {
        const el = audioElement.value;
        if (!el) return;

        if (playbackRequested) {
            playbackRequested = false;
            el.pause();
            isLoading.value = false;
            return;
        }

        playbackRequested = true;
        isLoading.value = true;

        el.play().catch((error) => handlePlayFailure(el, error));
    }

    function seek(time: number) {
        const el = audioElement.value;
        if (!el || !Number.isFinite(time)) return;
        el.currentTime = Math.max(0, Math.min(time, duration.value || time));
        currentTime.value = el.currentTime;
    }

    function setVolume(val: number) {
        const clamped = Math.max(0, Math.min(1, val));
        volume.value = clamped;
        if (audioElement.value) {
            audioElement.value.volume = clamped;
        }
    }

    function skip(delta: number) {
        seek(currentTime.value + delta);
    }

    function cycleSpeed() {
        const i = SPEEDS.indexOf(playbackRate.value as (typeof SPEEDS)[number]);
        playbackRate.value = SPEEDS[(i + 1) % SPEEDS.length];
        if (audioElement.value) audioElement.value.playbackRate = playbackRate.value;
    }

    function stop() {
        detach();
        clearMediaSession();
        currentAudio.value = null;
        currentChatSlug.value = null;
        isPlaying.value = false;
        isLoading.value = false;
        currentTime.value = 0;
        duration.value = 0;
        peaks.value = [];
    }

    return {
        currentAudio,
        currentChatSlug,
        isPlaying,
        isLoading,
        currentTime,
        duration,
        playbackRate,
        volume,
        peaks,
        audioElement,
        playAudio,
        togglePlay,
        seek,
        setVolume,
        skip,
        cycleSpeed,
        stop,
    };
});