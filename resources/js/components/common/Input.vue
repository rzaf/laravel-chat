<script setup lang="ts">
// Disabling inheritAttrs so we can explicitly bind extra native attributes to the input
defineOptions({
    inheritAttrs: false,
});

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
    <div class="w-full">
        <label v-if="label" class="block mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300" :for="name">
            {{ label }}
            <span v-if="isRequired" class="text-red-500 ml-0.5">*</span>
        </label>

        <div class="relative flex items-center">
            <input
                v-model="model"
                v-bind="$attrs"
                :name="name"
                :type="type"
                :required="isRequired"
                :disabled="isDisabled"
                :placeholder="placeholder"
                class="w-full px-3.5 py-2.5 text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition-all duration-200 border rounded-lg shadow-sm outline-none placeholder:text-gray-400 dark:placeholder:text-gray-500 border-gray-300 dark:border-gray-700 hover:border-gray-400 dark:hover:border-gray-600 focus:border-blue-600 dark:focus:border-blue-500 focus:ring-2 focus:ring-blue-600/20 dark:focus:ring-blue-500/20 disabled:bg-gray-100 disabled:dark:bg-gray-800 disabled:text-gray-400 disabled:cursor-not-allowed"
                :class="{
                    'border-red-500 dark:border-red-500 focus:border-red-500 focus:ring-red-500/20': errorStr !== '',
                }"
            />
            <div class="absolute right-3 flex items-center text-gray-400 ">
                <slot name="icon" />
            </div>
        </div>

        <transition name="fade">
            <p v-if="errorStr" class="mt-1.5 text-xs font-medium text-red-500 dark:text-red-400">
                {{ errorStr }}
            </p>
        </transition>
    </div>
</template>