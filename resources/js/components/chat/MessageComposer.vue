<script setup lang="ts">
import { nextTick, onMounted, useTemplateRef, watch } from 'vue';
import PaperClipIcon from '@/assets/svgs/PaperClip.svg';
import PaperAirplaneIcon from '@/assets/svgs/PaperAirplane.svg';
import EmojiPicker from './EmojiPicker.vue';
import EmojiAutoPicker from './EmojiAutoPicker.vue';

const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ (e: 'send'): void }>();
const props = defineProps<{ sending?: boolean }>();

const textareaRef = useTemplateRef<HTMLTextAreaElement>('textareaRef');
const autoPickerRef = useTemplateRef<InstanceType<typeof EmojiAutoPicker>>('autoPickerRef');

function autoResize(): void {
    const el = textareaRef.value;
    if (!el) return;
    el.style.height = '40px';
    const height = Math.min(el.scrollHeight, 160);
    el.style.height = `${height}px`;
}

function handleKeydown(e: KeyboardEvent): void {
    // Let autocomplete intercept keys first (ArrowUp, ArrowDown, Enter, Escape)
    const handled = autoPickerRef.value?.handleKeyDown(e);
    if (handled) return;

    // Regular Enter to send message
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (!props.sending && model.value.trim()) {
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

// Handles selection from the main emoji popover button
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
    <form class="relative z-10 w-full flex items-end gap-1 p-2 shrink-0" @submit.prevent="emit('send')">
        <EmojiAutoPicker ref="autoPickerRef" v-model="model" @select="replaceTextWithEmoji" />

        <PaperClipIcon
            class="h-10 p-1.5 rounded-full cursor-pointer hover:bg-slate-200 dark:hover:bg-slate-600 stroke-gray-800 dark:stroke-gray-300" />
        <EmojiPicker @select="handleEmojiSelect" />

        <textarea ref="textareaRef" v-model="model" placeholder="Message" :disabled="sending"
            class="w-full h-[40px] max-h-40 overflow-y-auto scrollable p-2 px-4 rounded-lg outline-none resize-none dark:bg-transparent disabled:cursor-not-allowed"
            @input="autoResize" @keydown="handleKeydown"></textarea>

        <button type="submit" :disabled="sending || !model.trim()"
            class="h-10 p-1.5 ml-2 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-full cursor-pointer disabled:cursor-not-allowed disabled:opacity-20">
            <PaperAirplaneIcon class="h-full stroke-gray-800 dark:stroke-gray-300" />
        </button>
    </form>
</template>