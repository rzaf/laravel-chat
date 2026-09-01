<script setup lang="ts">
import { nextTick, onMounted, useTemplateRef, watch } from 'vue';

const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ (e: 'send'): void }>();

const props = defineProps<{ sending?: boolean }>();

const textareaRef = useTemplateRef<HTMLTextAreaElement>('textareaRef');

function autoResize(): void {
    const el = textareaRef.value;
    if (!el) {
        return;
    }
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
}

function handleKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (!props.sending && model.value.trim()) {
            emit('send');
        }
    }
}

// Collapse back to a single line once the field is cleared after sending.
watch(model, (value) => {
    if (value === '') {
        void nextTick(() => {
            if (textareaRef.value) {
                textareaRef.value.style.height = 'auto';
                textareaRef.value.focus();
            }
        });
    }
});

onMounted(() => {
    textareaRef.value?.focus();
});
</script>

<template>
    <form class="z-10 flex items-center p-2 h-16 min-h-16 max-h-36" @submit.prevent="emit('send')">
        <svg
            class="self-end w-10 h-10 p-2 rounded-full cursor-pointer hover:bg-slate-200 dark:hover:bg-slate-600 stroke-gray-800 dark:stroke-gray-300"
            viewBox="0 0 24 24"
            fill="none"
        >
            <path
                d="M20 10.97 11.96 18.55C10.98 19.48 9.64 20 8.25 20S5.52 19.48 4.54 18.55C3.55 17.62 3 16.36 3 15.05S3.55 12.48 4.54 11.55L12.58 3.97C13.23 3.35 14.12 3 15.05 3S16.87 3.35 17.53 3.97C18.18 4.59 18.55 5.43 18.55 6.3S18.18 8.02 17.53 8.64L9.48 16.22C9.15 16.53 8.71 16.7 8.24 16.7S7.33 16.53 7 16.22C6.68 15.91 6.49 15.49 6.49 15.05S6.68 14.19 7 13.88L14.43 6.89"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>

        <textarea
            ref="textareaRef"
            v-model="model"
            placeholder="Message"
            :disabled="sending"
            class="w-full max-h-full p-2 scrollable px-4 rounded-lg outline-none resize-none dark:bg-transparent disabled:cursor-not-allowed"
            @input="autoResize"
            @keydown="handleKeydown"
        ></textarea>

        <button
            type="submit"
            :disabled="sending || !model.trim()"
            class="p-2 ml-2 text-gray-100 bg-blue-700 rounded-lg disabled:opacity-70 disabled:cursor-not-allowed"
        >
            Send
        </button>
    </form>
</template>
