<script setup lang="ts">
import { ref, watch, onMounted, useTemplateRef, onBeforeUnmount } from 'vue';
import emojiData from '@emoji-mart/data';
import { init, SearchIndex } from 'emoji-mart';

const model = defineModel<string>({ default: '' });

const emit = defineEmits<{
    (e: 'select', payload: { emoji: string; start: number; length: number }): void;
}>();

const showAutocomplete = ref(false);

const query = ref('');
const matchStart = ref(-1);
const selectedIndex = ref(0);

const containerRef = useTemplateRef<HTMLDivElement>('containerRef');

type EmojiMatch = { id: string; native: string };
const matches = ref<EmojiMatch[]>([]);

onMounted(() => {
    init({ data: emojiData });
    console.log('Emoji Auto picker : onMounted');
    document.addEventListener('mousedown', handleOutsideClick);
});

onBeforeUnmount(() => {
    console.log('Emoji Auto picker : onBeforeUnmount');
    document.removeEventListener('mousedown', handleOutsideClick);
});

// Close popup if click lands outside container
function handleOutsideClick(e: MouseEvent) {
    const el = e.target as HTMLElement;
    if (showAutocomplete.value && containerRef.value && !containerRef.value.contains(el)) {
        showAutocomplete.value = false;
    }
}

watch(model, async (text) => {
    const match = text.match(/:([a-zA-Z0-9_+-]{1,20})$/);
    if (match) {
        query.value = match[1];
        matchStart.value = text.length - match[0].length;

        const results = await SearchIndex.search(query.value);
        if (results && results.length > 0) {
            matches.value = results.slice(0, 6).map((item: any) => ({
                id: item.id,    
                native: item.skins[0].native,
            }));
            showAutocomplete.value = true;
            selectedIndex.value = 0;
        } else {
            matches.value = [];
            showAutocomplete.value = false;
        }
    } else {
        showAutocomplete.value = false;
    }
});

function confirmSelection(emojiNative: string) {
    emit('select', {
        emoji: emojiNative,
        start: matchStart.value,
        length: query.value.length + 1, // +1 for the colon
    });
    showAutocomplete.value = false;
}

// Return true if handled, allowing parent to skip 'Enter' submission
function handleKeyDown(e: KeyboardEvent): boolean {
    if (e.ctrlKey && e.code === 'Space') { // toggling popup
        e.preventDefault();
        showAutocomplete.value = !showAutocomplete.value ;
        return true;
    }
    
    if (!showAutocomplete.value || matches.value.length === 0) return false;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex.value = (selectedIndex.value + 1) % matches.value.length;
        return true;
    }
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex.value = (selectedIndex.value - 1 + matches.value.length) % matches.value.length;
        return true;
    }
    if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        confirmSelection(matches.value[selectedIndex.value].native);
        return true;
    }
    if (e.key === 'Escape') {
        showAutocomplete.value = false;
        return true;
    }
    return false;
}

defineExpose({ handleKeyDown });
</script>

<template>
    <div ref="containerRef" v-if="showAutocomplete && matches.length > 0"
        class="absolute bottom-full left-12 mb-2 w-64 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg overflow-hidden z-50">
        <div v-for="(emoji, index) in matches" :key="emoji.id"
            class="flex items-center gap-2 p-2 cursor-pointer transition-colors" :class="{
                'bg-indigo-50 dark:bg-slate-700': index === selectedIndex,
                'hover:bg-slate-100 dark:hover:bg-slate-700/50': index !== selectedIndex,
            }" @mousedown.prevent="confirmSelection(emoji.native)">
            <span class="text-xl">{{ emoji.native }}</span>
            <span class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">
                :{{ emoji.id }}:
            </span>
        </div>
    </div>
</template>