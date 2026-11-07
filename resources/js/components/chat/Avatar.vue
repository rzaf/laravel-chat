<script setup lang="ts">
import { ref, watch } from 'vue';

const props = defineProps<{
    src?: string;
}>();

const loaded = ref(false);

watch(() => props.src, () => {
    loaded.value = false;
});

</script>

<template>
    <div class="relative rounded-full overflow-hidden shrink-0">
        <div
            v-if="!loaded"
            class="absolute inset-0 animate-pulse bg-gray-300 dark:bg-gray-500"
        >
        </div>

        <img
            :src="src || '/storage/default-profile.png'"
            alt="profile-image"
            class="relative w-full h-full object-cover transition-opacity duration-200"
            :class="loaded ? 'opacity-100' : 'opacity-0'"
            loading="lazy"
            decoding="async"
            @load="loaded=true"
        />
    </div>
</template>