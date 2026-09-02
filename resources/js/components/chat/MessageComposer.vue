<script setup lang="ts">
import { nextTick, onMounted, useTemplateRef, watch } from 'vue';
import PaperClipIcon from '@/assets/svgs/PaperClip.svg';
import PaperAirplaneIcon from '@/assets/svgs/PaperAirplane.svg';

const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ (e: 'send'): void }>();

const props = defineProps<{ sending?: boolean }>();

const textareaRef = useTemplateRef<HTMLTextAreaElement>('textareaRef');

function autoResize(): void {
    const el = textareaRef.value;
    if (!el) return;

    el.style.height = '40px';
    const height = Math.min(el.scrollHeight, 160);
    el.style.height = `${height}px`;
}

function handleKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (!props.sending && model.value.trim()) {
            emit('send');
        }
    }
}

watch(model, (value) => {
    if (value === '') {
        void nextTick(() => {
            if (textareaRef.value) {
                textareaRef.value.style.height = '40px';
                requestAnimationFrame(() => {
                    textareaRef?.value?.focus();
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
    <form class="z-10 w-full flex items-end gap-1 p-2 shrink-0" @submit.prevent="emit('send')">
        <PaperClipIcon class="h-10 p-1.5 rounded-full cursor-pointer hover:bg-slate-200 dark:hover:bg-slate-600 stroke-gray-800 dark:stroke-gray-300" />

        <textarea
            ref="textareaRef"
            v-model="model"
            placeholder="Message"
            :disabled="sending"
            class="w-full h-[40px] max-h-40 overflow-y-auto scrollable p-2 px-4 rounded-lg outline-none resize-none dark:bg-transparent disabled:cursor-not-allowed"
            @input="autoResize"
            @keydown="handleKeydown"
        ></textarea>

        <button
            type="submit"
            :disabled="sending || !model.trim()"
            class="h-10 p-1.5 ml-2 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-full cursor-pointer disabled:cursor-not-allowed disabled:opacity-20"
        >
            <PaperAirplaneIcon class="h-full stroke-gray-800 dark:stroke-gray-300" />
        </button>
    </form>
</template>
