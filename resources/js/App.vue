<script setup lang="ts">
import { RouterView } from 'vue-router';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import GlobalLoader from './components/layout/GlobalLoader.vue';

</script>

<template>
    <GlobalLoader />
    <RouterView />
    <ConfirmModal />
</template>
