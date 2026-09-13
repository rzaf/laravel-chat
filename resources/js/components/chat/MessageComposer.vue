<script setup lang="ts">
import { nextTick, onMounted, useTemplateRef, watch, ref, computed } from 'vue';
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

const attachmentPreviews = computed(() => {
    return props.attachments.map(file => {
        // Only generate object URLs for safe, previewable types if desired, 
        // or for all files so they can be opened/downloaded in a new tab.
        const url = URL.createObjectURL(file);
        
        return {
            name: file.name,
            url: url,
            isImage: file.type.startsWith('image/'),
            isAudio: file.type.startsWith('audio/'),
            isVideo: file.type.startsWith('video/')
        };
    });
});

const fileInputRef = useTemplateRef<HTMLInputElement>('fileInputRef');
// const attachments = ref<File[]>([]);

function autoResize(): void {
    const el = textareaRef.value;
    if (!el) return;
    el.style.height = '40px';
    const height = Math.min(el.scrollHeight, 160);
    el.style.height = `${height}px`;
}

function handleFiles(e: Event): void {
    const input = e.target as HTMLInputElement;
    if (!input.files) return;
    props.attachments.push(...Array.from(input.files));
    input.value = '';
}

function removeAttachment(index: number): void {
    props.attachments.splice(index, 1);
}

function handleKeydown(e: KeyboardEvent): void {
    const handled = autoPickerRef.value?.handleKeyDown(e);
    if (handled) return;

    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();

        if (!props.sending && (model.value.trim() || props.attachments.length)) {
            emit('send');
        }
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
        el.focus();
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
        el.focus();
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
                    textareaRef.value?.focus();
                });
            }
        });
    }
});


onMounted(() => {
    textareaRef.value?.focus();
});
</script>

<template>
    <form class="relative z-50 w-full flex items-end gap-1 p-2 shrink-0" @submit.prevent="submit">

        <EmojiAutoPicker ref="autoPickerRef" v-model="model" @select="replaceTextWithEmoji" />

        <input ref="fileInputRef" type="file" multiple hidden @change="handleFiles" />

        <span class="h-10 p-1.5 ml-2 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-full cursor-pointer"
            @click="fileInputRef?.click()">
            <PaperClipIcon class="h-full stroke-gray-800 dark:stroke-gray-300" />
        </span>


        <EmojiPicker @select="handleEmojiSelect" />

        <textarea ref="textareaRef" v-model="model" placeholder="Message" :disabled="sending"
            class="w-full h-[40px] max-h-40 overflow-y-auto scrollable p-2 px-4 rounded-lg outline-none resize-none dark:bg-transparent disabled:cursor-not-allowed"
            @input="autoResize" @keydown="handleKeydown" />

        <div v-if="props.attachments.length" class="absolute bottom-full left-2 flex flex-col-reverse wrap-anywhere gap-2">
            <div v-for="(file, index) in attachmentPreviews" :key="index"
                class="flex items-center w-fit overflow-hidden rounded-lg rounded-bl-none bg-slate-300  hover:bg-slate-300 dark:bg-slate-700">
                <a :href="file.url" :title="file.name" target="_blank" rel="noopener noreferrer" class="flex items-center gap-1 hover:opacity-80">
                    <div class="w-8 h-8 ml-1">
                        <img v-if="file.isImage" :src="file.url" :alt="file.name" class="w-full h-full object-cover rounded-lg p-1" />
                        <MusicIcon v-else-if="file.isAudio" class="w-full h-full p-1" />
                        <VideoIcon v-else-if="file.isVideo" class="w-full h-full p-1" />
                        <DocumentTextIcon v-else class="w-full h-full p-1" />
                    </div>
                    <span class="text-xs max-w-26 truncate px-2 py-1 select-none">
                        {{ file.name }}
                    </span>
                </a>

                <!-- Remove attachment button -->
                <button type="button" class="cursor-pointer hover:backdrop-brightness-80 p-0.5 px-1.5 rounded-lg mr-1 text-red-500 font-bold" @click="removeAttachment(index)">
                    ×
                </button>
            </div>
        </div>


        <button type="submit" :disabled="sending || (!model.trim() && !props.attachments.length)"
            class="h-10 p-1.5 ml-2 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-full cursor-pointer disabled:cursor-not-allowed disabled:opacity-20">
            <PaperAirplaneIcon class="h-full stroke-gray-800 dark:stroke-gray-300" />
        </button>

    </form>
</template>