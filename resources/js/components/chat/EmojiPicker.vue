<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount, useTemplateRef } from "vue";

const emit = defineEmits<{
  (e: "select", emoji: string): void;
}>();

const isOpen = ref(false);
const pickerRef = useTemplateRef<HTMLDivElement>("pickerRef");
const buttonRef = useTemplateRef<HTMLButtonElement>("buttonRef");

let pickerInstance: HTMLElement | null = null;

function toggle() {
  isOpen.value = !isOpen.value;
}

async function loadPicker() {
  if (!pickerRef.value || pickerInstance) return;

  const { Picker } = await import("emoji-mart");
  const data = await import("@emoji-mart/data");

  const isDark = document.documentElement.classList.contains("dark");

  pickerInstance = new Picker({
    data: data.default,
    theme: isDark ? "dark" : "light",
    set: "native",
    locale: "en",
    previewPosition: "none",
    navPosition: "top",
    searchPosition: "sticky",
    perLine: 9,
    emojiSize: 24,
    emojiButtonSize: 34,
    maxFrequentRows: 2,
    onEmojiSelect(emoji: { native: string }) {
      emit("select", emoji.native);
      isOpen.value = false;
    },
    onSkinChange(skin: number) {
      localStorage.setItem("emoji-skin-tone", String(skin));
    },
  }) as unknown as HTMLElement;

  pickerRef.value.appendChild(pickerInstance);
}

function handleOutsideClick(e: MouseEvent) {
  const el = e.target as HTMLElement;

  if (
    isOpen.value &&
    !pickerRef.value?.contains(el) &&
    !buttonRef.value?.contains(el)
  ) {
    isOpen.value = false;
  }
}

function handleKeyDown(e: KeyboardEvent) {
  if (e.key === "Escape") {
    isOpen.value = false;
  }
}

watch(isOpen, async (visible) => {
  if (visible) {
    await loadPicker();

    if (pickerInstance && "update" in pickerInstance) {
      const isDark = document.documentElement.classList.contains("dark");
      (pickerInstance as any).update({
        theme: isDark ? "dark" : "light",
      });
    }
  }
});

onMounted(() => {
  document.addEventListener("mousedown", handleOutsideClick);
  document.addEventListener("keydown", handleKeyDown);
});

onBeforeUnmount(() => {
  document.removeEventListener("mousedown", handleOutsideClick);
  document.removeEventListener("keydown", handleKeyDown);
});
</script>

<template>
  <div class="relative inline-block">
    <button
      ref="buttonRef"
      type="button"
      class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
      @click="toggle"
      aria-label="Toggle Emoji Picker"
    >
        <svg class="w-6 h-6 stroke-gray-800 dark:stroke-gray-300 cursor-pointer" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="12" cy="12" r="10" />
            <path d="M8 14s1.5 2 4 2 4-2 4-2" stroke-linecap="round" />
            <circle cx="9" cy="9.5" r="1" fill="currentColor" stroke="none" />
            <circle cx="15" cy="9.5" r="1" fill="currentColor" stroke="none" />
        </svg>
    </button>

    <Transition name="fade">
      <div
        v-show="isOpen"
        class="absolute bottom-full left-0 mb-2 z-50 rounded-xl overflow-hidden"
      >
        <div ref="pickerRef" class="picker-container" />
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.picker-container {
  width: 352px;
  height: 435px;
}

.picker-container :deep(em-emoji-picker) {
  width: 100% !important;
  height: 100% !important;
  border: 0 !important;
  --border-radius: 12px;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>