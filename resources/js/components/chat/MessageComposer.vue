<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, useTemplateRef, watch, computed, ref } from 'vue';
import PaperClipIcon from '@/assets/svgs/PaperClip.svg';
import MusicIcon from '@/assets/svgs/Music.svg';
import VideoIcon from '@/assets/svgs/Video.svg';
import DocumentTextIcon from '@/assets/svgs/DocumentText.svg';
import PaperAirplaneIcon from '@/assets/svgs/PaperAirplane.svg';
import EmojiPicker from './EmojiPicker.vue';
import EmojiAutoPicker from './EmojiAutoPicker.vue';
import VoiceVideoRecorder from './VoiceVideoRecorder.vue';
import ImageCropModal, { isAnimatedImage, type CropAspect, type CropState } from '@/components/modals/ImageCropModal.vue';
import { UPLOAD_LIMITS, checkFileSize } from '@/config/uploadLimits';

const model = defineModel<string>({ default: '' });
const emit = defineEmits<{
    (e: 'send'): void;
    (e: 'sendAudio', file: File, onResult: (sent: boolean) => void): void;
    (e: 'sendVideo', file: File, onResult: (sent: boolean) => void): void
}>();
const props = defineProps<{ sending?: boolean, attachments: File[], recordingDisabled?: boolean }>();

const textareaRef = useTemplateRef<HTMLTextAreaElement>('textareaRef');
const autoPickerRef = useTemplateRef<InstanceType<typeof EmojiAutoPicker>>('autoPickerRef');
const fileInputRef = useTemplateRef<HTMLInputElement>('fileInputRef');
const recorderRef = useTemplateRef<InstanceType<typeof VoiceVideoRecorder>>('recorderRef');
const recordingActive = ref(false);

/* ------------------------------------------------------------- image cropping */

// Static formats only: cropping a GIF would flatten the animation.
const CROPPABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const isCroppable = (file: File) => CROPPABLE_TYPES.includes(file.type);
// Animated images (GIF, animated WebP / APNG) and mislabelled videos skip the cropper and are sent untouched.
const animatedFiles = new WeakSet<File>();
const canCropFile = (file: File) => isCroppable(file) && !animatedFiles.has(file);

const CHAT_ASPECTS: CropAspect[] = [
    { id: 'free', label: 'Free', ratio: 'free' },
    { id: 'original', label: 'Original', ratio: 'original' },
    { id: '1:1', label: '1:1', ratio: 1 },
    { id: '4:3', label: '4:3', ratio: 4 / 3 },
    { id: '3:4', label: '3:4', ratio: 3 / 4 },
    { id: '16:9', label: '16:9', ratio: 16 / 9 },
];

const cropQueue = ref<File[]>([]);
/** File currently shown in the crop modal (always the ORIGINAL, so re-cropping never loses quality). */
const cropFile = ref<File | null>(null);
/** Index in `attachments` being re-cropped; `null` = a brand-new image not attached yet. */
const cropIndex = ref<number | null>(null);
const cropInitialState = ref<CropState | null>(null);
const isCropping = computed(() => cropFile.value !== null);
const cropSubtitle = computed(() =>
    cropQueue.value.length ? `${cropQueue.value.length} more image${cropQueue.value.length === 1 ? '' : 's'} waiting` : '',
);

// cropped file -> the original it came from, and the crop settings used (so re-opening restores them)
const originalFiles = new WeakMap<File, File>();
const cropStates = new WeakMap<File, CropState>();

function advanceCrop(): void {
    cropIndex.value = null;
    cropInitialState.value = null;
    cropFile.value = cropQueue.value.shift() ?? null;
}

function startNextCrop(): void {
    if (!cropFile.value) advanceCrop();
}

function editAttachment(index: number): void {
    const file = props.attachments[index];
    if (!file || !canCropFile(file) || cropFile.value) return;
    cropIndex.value = index;
    cropInitialState.value = cropStates.get(file) ?? null;
    cropFile.value = originalFiles.get(file) ?? file;
}

function revokePreview(file: File): void {
    const url = objectUrlMap.get(file);
    if (url) {
        URL.revokeObjectURL(url);
        objectUrlMap.delete(file);
    }
}

function handleCropConfirm(file: File, state: CropState): void {
    const source = cropFile.value;
    if (!source) return;
    if (file !== source) originalFiles.set(file, source);
    cropStates.set(file, state);

    const index = cropIndex.value;
    const previous = index !== null ? props.attachments[index] : undefined;
    if (index !== null && previous) {
        if (previous !== file) revokePreview(previous);
        props.attachments.splice(index, 1, file);
    } else {
        props.attachments.push(file);
    }
    advanceCrop();
}

/* ---- upload limits (see src/config/uploadLimits.ts) ---- */

const limitError = ref('');
let limitErrorTimer: ReturnType<typeof setTimeout> | undefined;

function showLimitError(message: string): void {
    limitError.value = message;
    clearTimeout(limitErrorTimer);
    limitErrorTimer = setTimeout(() => (limitError.value = ''), 6000);
}

/** The image is over the dimension limit: drop it and carry on with the next one. */
function handleCropReject(message: string): void {
    showLimitError(message);
    advanceCrop();
}

/** Cancel: a new image is discarded, an already attached one stays as it was. */
function handleCropCancel(): void {
    advanceCrop();
}

// Cache object URLs to properly clean them up and prevent memory leaks
const objectUrlMap = new WeakMap<File, string>();

const attachmentPreviews = computed(() => {
    return props.attachments.map(file => {
        let url = objectUrlMap.get(file);
        if (!url) {
            url = URL.createObjectURL(file);
            objectUrlMap.set(file, url);
        }

        return {
            name: file.name,
            url,
            isImage: file.type.startsWith('image/'),
            canCrop: canCropFile(file),
            isAudio: file.type.startsWith('audio/'),
            isVideo: file.type.startsWith('video/')
        };
    });
});

function autoResize(): void {
    const el = textareaRef.value;
    if (!el) return;
    el.style.height = '40px';
    const height = Math.min(el.scrollHeight, 160);
    el.style.height = `${height}px`;
}

async function handleFiles(files: FileList | File[]): Promise<void> {
    // Copy first: clearing the <input> afterwards would empty a live FileList.
    for (const file of Array.from(files)) {
        const sizeError = checkFileSize(file, UPLOAD_LIMITS.maxAttachmentBytes);
        if (sizeError) {
            showLimitError(sizeError);
            continue;
        }
        if (isCroppable(file) && !(await isAnimatedImage(file))) {
            cropQueue.value.push(file); // opens the crop modal first
        } else {
            if (isCroppable(file)) animatedFiles.add(file);
            props.attachments.push(file);
        }
        startNextCrop();
    }
}

function handleFileInputChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    if (!input.files) return;
    handleFiles(input.files);
    input.value = '';
}

function handlePaste(e: ClipboardEvent): void {
    const items = e.clipboardData?.items;
    if (!items) return;

    const files: File[] = [];
    for (const item of items) {
        if (item.kind === 'file') {
            const file = item.getAsFile();
            if (file) {
                files.push(file);
            }
        }
    }

    if (files.length > 0) {
        e.preventDefault();
        handleFiles(files);
    }
}

function removeAttachment(index: number): void {
    const removedFile = props.attachments[index];
    if (removedFile) revokePreview(removedFile);
    props.attachments.splice(index, 1);
}

function handleKeydown(e: KeyboardEvent): void {
    const handled = autoPickerRef.value?.handleKeyDown(e);
    if (handled) return;

    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submit();
    }
}

/**
 * Programmatic focus opens the on-screen keyboard on phones/tablets (primary pointer is
 * "coarse"), covering the chat. Only auto-focus on devices with a mouse/trackpad.
 */
function canAutoFocus(): boolean {
    return !window.matchMedia('(pointer: coarse)').matches;
}

function replaceTextWithEmoji(payload: { emoji: string; start: number; length: number }): void {
    const el = textareaRef.value;
    if (!el) return;

    const before = model.value.substring(0, payload.start);
    const after = model.value.substring(payload.start + payload.length);

    model.value = before + payload.emoji + ' ' + after;

    nextTick(() => {
        const newPos = payload.start + payload.emoji.length + 1;
        el.focus({ preventScroll: true });
        el.setSelectionRange(newPos, newPos);
        autoResize();
    });
}

function handleEmojiSelect(emoji: string): void {
    const el = textareaRef.value;
    if (!el) return;

    const start = el.selectionStart;
    const end = el.selectionEnd;
    const text = model.value;
    model.value = text.substring(0, start) + emoji + text.substring(end);

    nextTick(() => {
        const newPos = start + emoji.length;
        el.focus({ preventScroll: true });
        el.setSelectionRange(newPos, newPos);
        autoResize();
    });
}

function handleRecordingState(active: boolean): void {
    recordingActive.value = active;
}

function submit(): void {
    if (!props.sending && !recordingActive.value && (model.value.trim() || props.attachments.length)) {
        emit('send');
    }
}

watch(model, (value) => {
    if (value === '') {
        void nextTick(() => {
            if (textareaRef.value) {
                textareaRef.value.style.height = '40px';
                if (canAutoFocus()) {
                    requestAnimationFrame(() => {
                        textareaRef.value?.focus({ preventScroll: true });
                    });
                }
            }
        });
    }
});

/** Focus the textarea (caret at the end). Exposed so the parent can call it. */
function focus(): void {
    if (!canAutoFocus()) return;
    // rAF: wait for Vue to flush the DOM and for a closing menu/modal to release focus.
    requestAnimationFrame(() => {
        const el = textareaRef.value;
        if (!el) return;
        el.focus({ preventScroll: true });
        el.setSelectionRange(el.value.length, el.value.length);
    });
}

function cancelRecording(): void {
    recorderRef.value?.cancelRecording();
}

defineExpose({ focus, cancelRecording, addFiles: handleFiles, isCropping });

onMounted(() => {
    if (canAutoFocus()) {
        textareaRef.value?.focus({ preventScroll: true });
    }
});

onUnmounted(() => {
    clearTimeout(limitErrorTimer);
    props.attachments.forEach(file => {
        const url = objectUrlMap.get(file);
        if (url) URL.revokeObjectURL(url);
    });
});
</script>

<template>
    <form class="relative z-50 w-full flex items-end gap-1 p-2 shrink-0 bg-white dark:bg-gray-800"
        @submit.prevent="submit">

        <EmojiAutoPicker ref="autoPickerRef" v-model="model" @select="replaceTextWithEmoji" />

        <input ref="fileInputRef" type="file" multiple hidden @change="handleFileInputChange" />

        <button type="button" aria-label="Attach file" :disabled="sending || recordingActive"
            class="h-10 p-1.5 ml-2 rounded-full cursor-pointer hover:bg-slate-200 dark:hover:bg-slate-600 disabled:cursor-not-allowed disabled:opacity-40"
            @click="fileInputRef?.click()">
            <PaperClipIcon class="h-full stroke-gray-800 dark:stroke-gray-300" />
        </button>

        <EmojiPicker v-if="!recordingActive" @select="handleEmojiSelect" />

        <textarea ref="textareaRef" v-model="model" placeholder="Message..." :disabled="sending || recordingActive"
            class="w-full h-[40px] max-h-40 overflow-y-auto scrollable p-2 px-4 rounded-lg outline-none resize-none dark:bg-transparent disabled:cursor-not-allowed"
            @input="autoResize" @keydown="handleKeydown" @paste="handlePaste" />

        <div v-if="props.attachments.length || limitError"
            class="absolute bottom-full left-2 flex flex-col-reverse items-start wrap-anywhere gap-2 max-w-[calc(100%-1rem)]">
            <div v-for="(file, index) in attachmentPreviews" :key="index"
                class="flex items-center w-fit overflow-hidden rounded-lg rounded-bl-none bg-slate-300 dark:bg-slate-700 shadow-sm">
                <!-- Images: click to open the crop modal again -->
                <button v-if="file.canCrop" type="button" :title="`Crop ${file.name}`"
                    class="flex items-center gap-1 cursor-pointer hover:opacity-80" @click="editAttachment(index)">
                    <div class="relative ml-1 size-11 shrink-0">
                        <img :src="file.url" :alt="file.name" class="object-cover w-full h-full p-1 rounded-lg" />
                        <span
                            class="absolute bottom-0.5 right-0.5 grid p-0.5 text-white rounded-full bg-black/60 size-4 place-items-center">
                            <svg viewBox="0 0 24 24" class="size-3" fill="none" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M6 2v14a2 2 0 0 0 2 2h14M18 22V8a2 2 0 0 0-2-2H2" />
                            </svg>
                        </span>
                    </div>
                    <span class="text-xs max-w-26 truncate px-2 py-1 select-none">{{ file.name }}</span>
                </button>

                <a v-else :href="file.url" :title="file.name" target="_blank" rel="noopener noreferrer"
                    class="flex items-center gap-1 hover:opacity-80">
                    <div class="w-8 h-8 ml-1">
                        <img v-if="file.isImage" :src="file.url" :alt="file.name"
                            class="w-full h-full object-cover rounded-lg p-1" />
                        <MusicIcon v-else-if="file.isAudio" class="w-full h-full p-1" />
                        <VideoIcon v-else-if="file.isVideo" class="w-full h-full p-1" />
                        <DocumentTextIcon v-else class="w-full h-full p-1" />
                    </div>
                    <span class="text-xs max-w-26 truncate px-2 py-1 select-none">
                        {{ file.name }}
                    </span>
                </a>

                <!-- Remove attachment button -->
                <button type="button" aria-label="Remove attachment"
                    class="cursor-pointer hover:brightness-90 p-0.5 px-1.5 rounded-lg mr-1 text-red-500 font-bold"
                    @click="removeAttachment(index)">
                    ×
                </button>
            </div>

            <button v-if="limitError" type="button" role="alert" @click="limitError = ''"
                class="px-3 py-2 text-xs text-left text-red-700 rounded-lg shadow-sm cursor-pointer bg-red-50 dark:bg-red-950/60 dark:text-red-200 max-w-80">
                {{ limitError }}
            </button>
        </div>

        <VoiceVideoRecorder ref="recorderRef" :disabled="!!props.sending || !!props.recordingDisabled"
            @recording-state="handleRecordingState" @send-audio="(file, onResult) => emit('sendAudio', file, onResult)"
            @send-video="(file, onResult) => emit('sendVideo', file, onResult)" />

        <button type="submit" aria-label="Send message"
            :disabled="sending || recordingActive || (!model.trim() && !props.attachments.length)"
            class="grid size-10 shrink-0 place-items-center rounded-full bg-blue-600 text-white transition hover:bg-blue-700 cursor-pointer disabled:cursor-not-allowed disabled:opacity-40">
            <PaperAirplaneIcon class="size-5" />
        </button>


        <ImageCropModal :file="cropFile" :aspects="CHAT_ASPECTS" :initial-state="cropInitialState"
            :subtitle="cropSubtitle" title="Crop image" confirm-text="Done"
            :max-input-dimension="UPLOAD_LIMITS.maxImageDimension" @confirm="handleCropConfirm"
            @cancel="handleCropCancel" @reject="handleCropReject" />
    </form>
</template>