<script setup lang="ts">
import { ref } from 'vue';

defineProps<{
    src?: string;
}>();

const loaded = ref(false);
</script>

<template>
    <div class="relative mr-3 rounded-full overflow-hidden bg-gray-200 shrink-0">
        <div
            v-if="!loaded"
            class="absolute inset-0 animate-pulse bg-gray-300"
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