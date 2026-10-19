<script setup lang="ts">
import { useLoading } from '@/composables/useLoading';

const { isLoading } = useLoading();
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
    >
        <div v-if="isLoading" class="app-loader-overlay" role="status" aria-live="polite" aria-busy="true">
            <div class="app-spinner" aria-hidden="true"></div>
            <span class="sr-only">Loading…</span>
        </div>
    </Transition>
</template>