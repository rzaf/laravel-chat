<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, useTemplateRef, watch, computed } from 'vue';
import PaperClipIcon from '@/assets/svgs/PaperClip.svg';
import MusicIcon from '@/assets/svgs/Music.svg';
import VideoIcon from '@/assets/svgs/Video.svg';
import DocumentTextIcon from '@/assets/svgs/DocumentText.svg';
import PaperAirplaneIcon from '@/assets/svgs/PaperAirplane.svg';
import EmojiPicker from './EmojiPicker.vue';
import EmojiAutoPicker from './EmojiAutoPicker.vue';

const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ (e: 'send'): void }>();
const props = defineProps<{ sending?: boolean, attachments: File[] }>();

const textareaRef = useTemplateRef<HTMLTextAreaElement>('textareaRef');
const autoPickerRef = useTemplateRef<InstanceType<typeof EmojiAutoPicker>>('autoPickerRef');
const fileInputRef = useTemplateRef<HTMLInputElement>('fileInputRef');

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

function handleFiles(files: FileList | File[]): void {
    props.attachments.push(...Array.from(files));
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
    if (removedFile) {
        const url = objectUrlMap.get(removedFile);
        if (url) {
            URL.revokeObjectURL(url);
            objectUrlMap.delete(removedFile);
        }
    }
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

function submit(): void {
    if (!props.sending && (model.value.trim() || props.attachments.length)) {
        emit('send');
    }
}

watch(model, (value) => {
    if (value === '') {
        void nextTick(() => {
            if (textareaRef.value) {
                textareaRef.value.style.height = '40px';
                requestAnimationFrame(() => {
                    textareaRef.value?.focus({ preventScroll: true });
                });
            }
        });
    }
});

onMounted(() => {
    textareaRef.value?.focus({ preventScroll: true });
});

onUnmounted(() => {
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

        <button type="button" aria-label="Attach file"
            class="h-10 p-1.5 ml-2 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-full cursor-pointer"
            @click="fileInputRef?.click()">
            <PaperClipIcon class="h-full stroke-gray-800 dark:stroke-gray-300" />
        </button>

        <EmojiPicker @select="handleEmojiSelect" />

        <textarea ref="textareaRef" v-model="model" placeholder="Message..." :disabled="sending"
            class="w-full h-[40px] max-h-40 overflow-y-auto scrollable p-2 px-4 rounded-lg outline-none resize-none dark:bg-transparent disabled:cursor-not-allowed"
            @input="autoResize" @keydown="handleKeydown" @paste="handlePaste" />

        <div v-if="props.attachments.length"
            class="absolute bottom-full left-2 flex flex-col-reverse wrap-anywhere gap-2">
            <div v-for="(file, index) in attachmentPreviews" :key="index"
                class="flex items-center w-fit overflow-hidden rounded-lg rounded-bl-none bg-slate-300 dark:bg-slate-700 shadow-sm">
                <a :href="file.url" :title="file.name" target="_blank" rel="noopener noreferrer"
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
        </div>

        <button type="submit" aria-label="Send message"
            :disabled="sending || (!model.trim() && !props.attachments.length)"
            class="h-10 p-1.5 ml-2 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-full cursor-pointer disabled:cursor-not-allowed disabled:opacity-20">
            <PaperAirplaneIcon class="h-full stroke-gray-800 dark:stroke-gray-300" />
        </button>

    </form>
</template>