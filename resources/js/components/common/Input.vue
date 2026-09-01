<script setup lang="ts">
withDefaults(
    defineProps<{
        name: string;
        type?: string;
        label?: string;
        placeholder?: string;
        errorStr?: string;
        isRequired?: boolean;
        isDisabled?: boolean;
    }>(),
    {
        type: 'text',
        errorStr: '',
        isRequired: true,
        isDisabled: false,
    },
);

const model = defineModel<string>();
</script>

<template>
    <div>
        <label v-if="label != null" class="block mb-2 text-sm" :for="name">{{ label }}</label>

        <div class="relative flex items-center">
            <input
                v-model="model"
                :name="name"
                :type="type"
                :required="isRequired"
                :disabled="isDisabled"
                :placeholder="placeholder"
                class="w-full px-4 py-3 text-sm transition-colors border-2 border-gray-400 rounded-md outline-none dark:focus:border-blue-600 focus:border-blue-500 hover:border-blue-500 dark:border-gray-500 dark:hover:border-blue-600 dark:bg-gray-800"
                :class="{ 'border-red-600': errorStr != '' }"
            />
            <slot name="icon" />
        </div>
        <p v-show="errorStr != ''" class="p-1 text-sm text-red-600">{{ errorStr }}</p>
    </div>
</template>
