<script setup lang="ts">
import { computed, onUnmounted, ref, nextTick } from 'vue';
import fixWebmDuration from 'fix-webm-duration';
import MicIcon from '@/assets/svgs/Mic.svg';
import VideoIcon from '@/assets/svgs/Video.svg';
import CloseIcon from '@/assets/svgs/Close.svg';
import PaperAirplaneIcon from '@/assets/svgs/PaperAirplane.svg';

const emit = defineEmits<{
    (e: 'recordingState', active: boolean): void;
    (e: 'sendAudio', file: File, onResult: (sent: boolean) => void): void;
    (e: 'sendVideo', file: File, onResult: (sent: boolean) => void): void;
}>();

const props = defineProps<{ disabled?: boolean }>();

type RecordingMode = 'audio' | 'video';
type RecorderState = 'idle' | 'starting' | 'recording' | 'paused' | 'stopping' | 'sending' | 'failed';

const mode = ref<RecordingMode>('audio');
const state = ref<RecorderState>('idle');
const recordingDuration = ref(0);
const recordingError = ref('');
const videoPreviewStream = ref<MediaStream | null>(null);
const videoRef = ref<HTMLVideoElement | null>(null);
const audioLevels = ref<number[]>([18, 30, 46, 24, 62, 38, 72, 34, 52, 22, 64, 40, 28, 56, 32, 20]);
const modeHint = ref('');
let modeHintTimer: ReturnType<typeof setTimeout> | null = null;

const statusLabel = computed(() => {
    if (state.value === 'starting') return 'Connecting…';
    if (state.value === 'stopping') return 'Finishing recording…';
    if (state.value === 'sending') return 'Sending…';
    if (state.value === 'failed') return 'Could not send';
    if (state.value === 'paused') return 'Recording paused';
    return activeMode === 'video' ? 'Video message' : 'Voice message';
});

function setState(next: RecorderState): void {
    state.value = next;
    emit('recordingState', next !== 'idle');
}

let mediaRecorder: MediaRecorder | null = null;
let mediaStream: MediaStream | null = null;
let recordingChunks: Blob[] = [];
let pendingFile: File | null = null;
let activeMode: RecordingMode = 'audio';
let discardRecording = false;
let isUnmounted = false;
let startRequestId = 0;
let recordedDurationMs = 0;
let elapsedBeforeSegment = 0;
let segmentStartedAt = 0;

let recordingTimer: ReturnType<typeof setInterval> | null = null;
let pressTimer: ReturnType<typeof setTimeout> | null = null;
let longPressTriggered = false;
const HOLD_TO_SWITCH_MS = 450;

// Web Audio API for Live Waveform Visualizer
let audioContext: AudioContext | null = null;
let analyser: AnalyserNode | null = null;
let animFrameId: number | null = null;
let animateAudioLevels: (() => void) | null = null;

function formatDuration(seconds: number): string {
    const mins = Math.floor(seconds / 60).toString().padStart(2, '0');
    const secs = (seconds % 60).toString().padStart(2, '0');
    return `${mins}:${secs}`;
}

function clearRecordingTimer(): void {
    if (recordingTimer) clearInterval(recordingTimer);
    recordingTimer = null;
}

function updateDuration(): void {
    const elapsed = elapsedBeforeSegment + (state.value === 'recording' ? Date.now() - segmentStartedAt : 0);
    recordingDuration.value = Math.floor(elapsed / 1000);
}

function startDurationTimer(): void {
    clearRecordingTimer();
    recordingTimer = setInterval(updateDuration, 250);
}

function clearPressTimer(): void {
    if (pressTimer) clearTimeout(pressTimer);
    pressTimer = null;
}

function showModeHint(): void {
    modeHint.value = mode.value === 'video' ? 'Video mode' : 'Voice mode';
    if (modeHintTimer) clearTimeout(modeHintTimer);
    modeHintTimer = setTimeout(() => {
        modeHint.value = '';
        modeHintTimer = null;
    }, 1400);
}

// -----------------------------------------------------------------------------
// Live Audio Spectrum Visualization
// -----------------------------------------------------------------------------
function setupAudioAnalyzer(stream: MediaStream): void {
    try {
        const AudioCtx = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
        audioContext = new AudioCtx();
        analyser = audioContext.createAnalyser();
        analyser.fftSize = 32;
        const source = audioContext.createMediaStreamSource(stream);
        source.connect(analyser);

        const dataArray = new Uint8Array(analyser.frequencyBinCount);
        void audioContext.resume();
        const updateLevels = () => {
            if (!analyser || state.value !== 'recording') return;
            analyser.getByteFrequencyData(dataArray);

            // Use more bars for a smoother, less mechanical-looking level meter.
            const bars: number[] = [];
            for (let i = 0; i < 16; i++) {
                const index = Math.min(dataArray.length - 1, Math.floor(i * dataArray.length / 16));
                const value = dataArray[index] || 0;
                bars.push(Math.max(10, Math.round((value / 255) * 100)));
            }
            audioLevels.value = bars;
            animFrameId = requestAnimationFrame(updateLevels);
        };
        // Save the updater so it can restart after a pause.
        animateAudioLevels = updateLevels;
    } catch {
        // Fallback static height if Web Audio API blocked
        audioLevels.value = [20, 32, 45, 28, 70, 42, 86, 35, 58, 24, 72, 40, 62, 30, 48, 22];
    }
}

function stopAudioAnalyzer(): void {
    if (animFrameId) cancelAnimationFrame(animFrameId);
    animFrameId = null;
    if (audioContext && audioContext.state !== 'closed') {
        void audioContext.close();
    }
    audioContext = null;
    analyser = null;
    animateAudioLevels = null;
}

// -----------------------------------------------------------------------------
// Pointer / Gesture Handling
// -----------------------------------------------------------------------------
function handlePointerDown(event: PointerEvent): void {
    if (props.disabled || state.value !== 'idle') return;
    longPressTriggered = false;
    (event.currentTarget as HTMLButtonElement).setPointerCapture?.(event.pointerId);
    clearPressTimer();

    pressTimer = setTimeout(() => {
        longPressTriggered = true;
        toggleMode();
    }, HOLD_TO_SWITCH_MS);
}

function handlePointerUp(): void {
    if (props.disabled || state.value !== 'idle') {
        clearPressTimer();
        return;
    }
    const wasLongPress = longPressTriggered;
    clearPressTimer();
    longPressTriggered = false;

    if (!wasLongPress) {
        void startRecording();
    }
}

function handlePointerCancel(): void {
    clearPressTimer();
    longPressTriggered = false;
}

function toggleMode(): void {
    if (state.value !== 'idle') return;
    mode.value = mode.value === 'audio' ? 'video' : 'audio';
    showModeHint();
}

// Pointer gestures cover mouse/touch; detail === 0 covers keyboard activation.
function handleTriggerClick(event: MouseEvent): void {
    if (event.detail === 0 && !props.disabled && state.value === 'idle') {
        void startRecording();
    }
}

function releaseStream(): void {
    stopAudioAnalyzer();
    mediaStream?.getTracks().forEach(track => track.stop());
    mediaStream = null;
    videoPreviewStream.value = null;
}

function resetRecording(): void {
    clearRecordingTimer();
    releaseStream();
    mediaRecorder = null;
    recordingChunks = [];
    pendingFile = null;
    discardRecording = false;
    elapsedBeforeSegment = 0;
    segmentStartedAt = 0;
    recordingDuration.value = 0;
    recordedDurationMs = 0;
    setState('idle');
}

function getSupportedMimeType(recordingMode: RecordingMode): string | undefined {
    if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return undefined;
    const candidates = recordingMode === 'video'
        ? ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/mp4', 'video/webm']
        : ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm', 'audio/ogg'];
    return candidates.find(type => MediaRecorder.isTypeSupported(type));
}

function getFileExtension(mimeType: string, recordingMode: RecordingMode): string {
    const mime = mimeType.split(';')[0].trim().toLowerCase();
    if (mime === 'audio/mp4') return 'm4a';
    if (mime === 'video/mp4') return 'mp4';
    if (mime === 'audio/ogg') return 'ogg';
    return recordingMode === 'video' ? 'webm' : 'webm';
}

function addWebmDuration(blob: Blob, durationMs: number): Promise<Blob> {
    return new Promise(resolve => {
        try {
            fixWebmDuration(blob, durationMs, resolve);
        } catch (error) {
            console.warn('Could not add WebM duration metadata:', error);
            resolve(blob);
        }
    });
}

// -----------------------------------------------------------------------------
// Core Recorder Engine
// -----------------------------------------------------------------------------
async function startRecording(): Promise<void> {
    if (props.disabled || state.value !== 'idle') return;
    recordingError.value = '';

    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        recordingError.value = 'Recording is not supported by your browser.';
        return;
    }

    const requestId = ++startRequestId;
    activeMode = mode.value;
    setState('starting');

    try {
        const constraints: MediaStreamConstraints = activeMode === 'video'
            ? { audio: true, video: { width: { ideal: 480 }, height: { ideal: 480 }, facingMode: 'user' } }
            : { audio: true };

        const stream = await navigator.mediaDevices.getUserMedia(constraints);

        if (isUnmounted || requestId !== startRequestId) {
            stream.getTracks().forEach(track => track.stop());
            return;
        }

        mediaStream = stream;
        if (activeMode === 'video') {
            videoPreviewStream.value = stream;
            await nextTick();
            if (isUnmounted || requestId !== startRequestId) {
                stream.getTracks().forEach(track => track.stop());
                return;
            }
            if (videoRef.value) videoRef.value.srcObject = stream;
        } else {
            setupAudioAnalyzer(stream);
        }

        const mimeType = getSupportedMimeType(activeMode);
        const recorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);

        mediaRecorder = recorder;
        recordingChunks = [];
        discardRecording = false;
        elapsedBeforeSegment = 0;
        recordingDuration.value = 0;

        recorder.ondataavailable = event => {
            if (event.data.size > 0) recordingChunks.push(event.data);
        };

        recorder.onerror = () => {
            discardRecording = true;
            recordingError.value = 'Recording interrupted. Try again.';
            resetRecording();
        };

        recorder.onstop = async () => {
            const chunks = recordingChunks;
            recordingChunks = [];
            const rawMime = recorder.mimeType || chunks.find(chunk => chunk.type)?.type || (activeMode === 'video' ? 'video/webm' : 'audio/webm');
            const mime = rawMime.split(';')[0].trim().toLowerCase();
            let blob = new Blob(chunks, { type: mime });

            clearRecordingTimer();
            releaseStream();
            mediaRecorder = null;

            if (discardRecording || requestId !== startRequestId || isUnmounted) {
                resetRecording();
                return;
            }

            if (!blob.size) {
                recordingError.value = 'Recorded file is empty.';
                resetRecording();
                return;
            }

            if ((mime === 'audio/webm' || mime === 'video/webm') && recordedDurationMs > 0) {
                blob = await addWebmDuration(blob, recordedDurationMs);
            }

            // The user may switch chats while the WebM metadata is being finalized.
            if (discardRecording || requestId !== startRequestId || isUnmounted) {
                resetRecording();
                return;
            }

            const extension = getFileExtension(mime, activeMode);
            const prefix = activeMode === 'video' ? 'video-note' : 'voice-note';
            pendingFile = new File([blob], `${prefix}-${Date.now()}.${extension}`, {
                type: mime,
                lastModified: Date.now(),
            });

            setState('sending');
            sendPendingRecording();
        };

        recorder.start(250);
        segmentStartedAt = Date.now();
        setState('recording');
        startDurationTimer();
        animateAudioLevels?.();
    } catch (err) {
        console.error('Recording initialization failed:', err);
        if (requestId !== startRequestId || isUnmounted) return;
        recordingError.value = 'Microphone/Camera permission denied.';
        resetRecording();
    }
}

function pauseRecording(): void {
    if (state.value !== 'recording' || !mediaRecorder || mediaRecorder.state !== 'recording') return;
    elapsedBeforeSegment += Date.now() - segmentStartedAt;
    clearRecordingTimer();
    setState('paused');
    updateDuration();
    mediaRecorder.pause();
}

function resumeRecording(): void {
    if (state.value !== 'paused' || !mediaRecorder || mediaRecorder.state !== 'paused') return;
    mediaRecorder.resume();
    segmentStartedAt = Date.now();
    setState('recording');
    startDurationTimer();
    animateAudioLevels?.();
}

function stopAndSend(): void {
    if (state.value !== 'recording' && state.value !== 'paused') return;
    recordedDurationMs = elapsedBeforeSegment + (state.value === 'recording' ? Date.now() - segmentStartedAt : 0);
    recordingDuration.value = Math.floor(recordedDurationMs / 1000);
    discardRecording = false;
    clearRecordingTimer();
    setState('stopping');
    mediaRecorder?.stop();
}

function cancelRecording(): void {
    clearPressTimer();

    if (state.value === 'idle') return;

    startRequestId++;
    discardRecording = true;
    clearRecordingTimer();

    if (state.value === 'starting' || state.value === 'failed') {
        resetRecording();
        recordingError.value = '';
        return;
    }

    if (state.value === 'stopping') return;

    if (state.value === 'recording' || state.value === 'paused') {
        setState('stopping');
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        } else {
            resetRecording();
        }
        return;
    }

    // The upload has already been handed to the parent; it cannot be aborted here.
}

function sendPendingRecording(): void {
    if (!pendingFile || state.value !== 'sending') return;
    const file = pendingFile;
    const onResult = (sent: boolean) => {
        if (sent) {
            recordingError.value = '';
            resetRecording();
        } else {
            setState('failed');
            recordingError.value = 'Failed to upload recording. Tap retry.';
        }
    };

    if (activeMode === 'video') emit('sendVideo', file, onResult);
    else emit('sendAudio', file, onResult);
}

defineExpose({ cancelRecording });

onUnmounted(() => {
    isUnmounted = true;
    startRequestId++;
    clearPressTimer();
    clearRecordingTimer();
    if (modeHintTimer) clearTimeout(modeHintTimer);
    modeHintTimer = null;
    discardRecording = true;
    try {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
        else releaseStream();
    } catch {
        releaseStream();
    }
});
</script>

<template>
    <div class="relative flex items-center">
        <!-- One-button trigger: tap to record, hold to switch voice/video. -->
        <div v-if="state === 'idle'" class="flex items-center">
            <button type="button" :disabled="disabled" :aria-label="`Record ${mode} message. Hold to switch mode.`"
                :title="`${mode === 'audio' ? 'Voice' : 'Video'} message · Hold to switch`"
                class="grid size-10 shrink-0 place-items-center rounded-full p-1.5 cursor-pointer hover:bg-slate-200 dark:hover:bg-slate-600 disabled:cursor-not-allowed disabled:opacity-40"
                @pointerdown="handlePointerDown" @pointerup="handlePointerUp" @pointercancel="handlePointerCancel"
                @lostpointercapture="handlePointerCancel" @contextmenu.prevent>
                <VideoIcon v-if="mode === 'video'" class="size-6 text-gray-800 dark:text-gray-300" />
                <MicIcon v-else class="size-6 text-gray-800 dark:text-gray-300" />
            </button>
        </div>

        <!-- Compact recording dock, anchored above the composer instead of the viewport. -->
        <Transition enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-2 scale-[0.98] opacity-0" leave-active-class="transition duration-150 ease-in"
            leave-to-class="translate-y-1 scale-[0.98] opacity-0">
            <div v-if="state !== 'idle'"
                class="absolute bottom-full right-0 z-50 mb-3 w-[min(22rem,calc(100vw-1.5rem))] rounded-2xl border border-gray-200/90 bg-white/95 p-3 shadow-xl shadow-gray-900/10 backdrop-blur-xl dark:border-gray-700 dark:bg-gray-800/95 dark:shadow-black/30"
                role="group"
                :aria-label="activeMode === 'video' ? 'Video recording controls' : 'Voice recording controls'">
                <div class="flex min-w-0 items-center gap-3">
                    <!-- Video thumbnail / audio waveform -->
                    <div v-if="activeMode === 'video' && videoPreviewStream"
                        class="relative size-12 shrink-0 overflow-hidden rounded-xl bg-gray-950 ring-1 ring-black/10 dark:ring-white/10">
                        <video ref="videoRef" autoplay muted playsinline class="size-full object-cover" />
                        <!-- <span
                            class="absolute bottom-1 left-1 rounded bg-black/60 px-1 py-0.5 text-[9px] font-semibold tracking-wide text-white">LIVE</span> -->
                    </div>
                    <div v-else-if="activeMode === 'audio'"
                        class="flex h-12 w-12 shrink-0 items-center justify-center gap-[2px] overflow-hidden rounded-xl bg-blue-50 px-2 dark:bg-blue-950/50"
                        :aria-label="state === 'paused' ? 'Waveform paused' : 'Live audio levels'">
                        <span v-for="(height, index) in audioLevels" :key="index"
                            class="w-[2px] rounded-full bg-blue-500 transition-[height] duration-100 dark:bg-blue-400"
                            :style="{ height: `${state === 'paused' ? 12 : height}%`, opacity: state === 'paused' ? 0.45 : 1 }" />
                    </div>
                    <div v-else
                        class="grid size-12 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300">
                        <VideoIcon class="size-5" />
                    </div>

                    <!-- State and elapsed time -->
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="relative flex size-2 shrink-0">
                                <span v-if="state === 'recording'"
                                    class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-60" />
                                <span class="relative inline-flex size-2 rounded-full"
                                    :class="state === 'recording' ? 'bg-red-500' : state === 'paused' ? 'bg-amber-500' : state === 'failed' ? 'bg-red-500' : 'bg-gray-400'" />
                            </span>
                            <span class="truncate text-sm font-semibold text-gray-800 dark:text-gray-100">
                                {{ statusLabel }}
                            </span>
                        </div>
                        <div class="mt-0.5 flex items-baseline gap-1.5">
                            <span
                                class="font-mono text-base font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">
                                {{ formatDuration(recordingDuration) }}
                            </span>
                            <span class="text-[11px] text-gray-500 dark:text-gray-400">
                                {{ state === 'paused' ? 'Tap resume when ready' : activeMode === 'video' ? 'Camera on' :
                                    'Live recording' }}
                            </span>
                        </div>
                    </div>

                    <!-- Primary controls -->
                    <div class="ml-auto flex shrink-0 items-center gap-1.5">
                        <button
                            v-if="state === 'starting' || state === 'recording' || state === 'paused' || state === 'failed'"
                            type="button" :aria-label="state === 'failed' ? 'Discard recording' : 'Cancel recording'"
                            :title="state === 'failed' ? 'Discard' : 'Cancel'"
                            class="grid size-9 place-items-center rounded-full text-gray-500 transition-colors hover:bg-red-50 hover:text-red-600 dark:text-gray-400 dark:hover:bg-red-950/50 dark:hover:text-red-400"
                            @click="cancelRecording">
                            <CloseIcon class="size-[17px]" />
                        </button>

                        <button v-if="state === 'recording' || state === 'paused'" type="button"
                            :aria-label="state === 'paused' ? 'Resume recording' : 'Pause recording'"
                            :title="state === 'paused' ? 'Resume' : 'Pause'"
                            class="grid size-9 place-items-center rounded-full bg-gray-100 text-gray-700 transition-colors hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600"
                            @click="state === 'paused' ? resumeRecording() : pauseRecording()">
                            <svg v-if="state === 'paused'" viewBox="0 0 24 24" class="size-4 fill-current">
                                <path d="M7 4.5v15l12-7.5z" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" class="size-4 fill-current">
                                <rect x="6" y="5" width="4" height="14" rx="1" />
                                <rect x="14" y="5" width="4" height="14" rx="1" />
                            </svg>
                        </button>

                        <button v-if="state === 'recording' || state === 'paused'" type="button"
                            aria-label="Stop and send recording" title="Stop & send"
                            class="grid size-9 place-items-center rounded-full bg-blue-600 text-white shadow-sm shadow-blue-600/20 transition hover:bg-blue-700 active:scale-95"
                            @click="stopAndSend">
                            <PaperAirplaneIcon class="size-[17px]" />
                        </button>

                        <button v-else-if="state === 'failed'" type="button"
                            class="rounded-full bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700 active:scale-95"
                            @click="sendPendingRecording">
                            Retry
                        </button>

                        <span v-else-if="state === 'starting' || state === 'stopping' || state === 'sending'"
                            class="grid size-9 place-items-center" role="status" aria-label="Please wait">
                            <span
                                class="size-4 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600 dark:border-gray-600 dark:border-t-blue-400" />
                        </span>
                    </div>
                </div>

                <p v-if="state === 'failed' && recordingError"
                    class="mt-2 border-t border-gray-100 pt-2 text-xs text-red-600 dark:border-gray-700 dark:text-red-400">
                    {{ recordingError }}
                </p>
                <p v-else-if="state === 'recording'" class="mt-2 text-[11px] text-gray-400 dark:text-gray-500">
                    {{ activeMode === 'video' ? 'Keep your face in frame. Stop to send this video.' : 'Your voice is being recorded. Stop to send it.' }}
                </p>
            </div>
        </Transition>

        <!-- Permission / initialization errors -->
        <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="translate-y-1 opacity-0"
            leave-active-class="transition duration-100 ease-in" leave-to-class="translate-y-1 opacity-0">
            <div v-if="recordingError && state === 'idle'" role="alert"
                class="absolute bottom-full right-0 z-50 mb-3 flex max-w-[min(19rem,calc(100vw-1.5rem))] items-start gap-2 rounded-xl border border-red-200 bg-white px-3 py-2.5 text-xs text-red-700 shadow-lg dark:border-red-900 dark:bg-gray-800 dark:text-red-300">
                <span class="min-w-0 flex-1">{{ recordingError }}</span>
                <button type="button" aria-label="Dismiss error"
                    class="shrink-0 font-semibold opacity-70 hover:opacity-100" @click="recordingError = ''">×</button>
            </div>
        </Transition>
    </div>
</template>