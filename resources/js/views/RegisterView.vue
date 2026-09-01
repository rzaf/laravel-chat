<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink } from 'vue-router';
import useAuthStore from '@/stores/auth';
import { register as apiRegister } from '@/api';
import { useFormErrors } from '@/composables/useFormErrors';
import InputComponent from '@/components/common/Input.vue';

const auth = useAuthStore();
const {
    errors,
    formError,
    setFromError,
    clear,
} = useFormErrors(['name', 'username', 'email', 'password']);

const name = ref('');
const username = ref('');
const email = ref('');
const password = ref('');
const confirmPassword = ref('');
const confirmPasswordError = ref('');
const isSending = ref(false);

async function register(): Promise<void> {
    if (isSending.value) return;
    isSending.value = true;
    clear();
    confirmPasswordError.value = '';

    try {
        const res = await apiRegister({
            name: name.value,
            username: username.value,
            email: email.value,
            password: password.value,
            password_confirmation: confirmPassword.value,
        });
        auth.setUser(res);
        // Redirect happens via the router guard (guestOnly → home).
    } catch (err: unknown) {
        setFromError(err);

        // Also check for confirm-password mismatch from backend.
        const axiosErr = err as { response?: { data?: { errors?: Record<string, string[]> } } };
        const serverErrors = axiosErr.response?.data?.errors;
        if (serverErrors?.password_confirmation) {
            confirmPasswordError.value = serverErrors.password_confirmation[0];
        }
    } finally {
        isSending.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col items-center justify-center h-full px-4 py-6 overflow-auto min-h-fit">
        <div class="w-full sm:max-w-md">
            <div class="bg-white sm:p-8 sm:shadow-equal dark:bg-gray-800 rounded-2xl">
                <h2 class="text-2xl font-bold text-center">Sign up</h2>
                <form class="mt-8 space-y-4" @submit.prevent="register">
                    <InputComponent v-model="name" :error-str="errors.name" name="name" label="Name" />
                    <InputComponent v-model="username" :error-str="errors.username" name="username" label="Username" />
                    <InputComponent v-model="email" :error-str="errors.email" name="email" label="Email" />
                    <InputComponent
                        v-model="password"
                        :error-str="errors.password"
                        type="password"
                        name="password"
                        label="Password"
                    />
                    <InputComponent
                        v-model="confirmPassword"
                        :error-str="confirmPasswordError"
                        type="password"
                        name="confirm_password"
                        label="Confirm Password"
                    />

                    <p v-show="formError" class="px-1 text-sm text-red-600">{{ formError }}</p>

                    <div class="!mt-8">
                        <button
                            :disabled="isSending"
                            type="submit"
                            :class="{ 'cursor-not-allowed': isSending, 'animate-pulse': isSending }"
                            class="w-full px-4 py-3 text-sm tracking-wide text-white bg-blue-600 border-2 border-blue-600 rounded-lg outline-none cursor-pointer hover:bg-blue-700 focus:border-blue-300"
                        >
                            Sign up
                        </button>
                    </div>
                    <p class="text-sm !mt-8 text-center">
                        Already have an account?
                        <RouterLink
                            to="sign-in"
                            class="ml-1 font-semibold text-blue-600 hover:underline whitespace-nowrap"
                        >
                            Login here
                        </RouterLink>
                    </p>
                </form>
            </div>
        </div>
    </div>
</template>
