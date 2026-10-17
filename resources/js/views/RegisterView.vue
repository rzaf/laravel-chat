<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import useAuthStore from '@/stores/auth';
import { register as apiRegister } from '@/api';
import { useFormErrors } from '@/composables/useFormErrors';
import InputComponent from '@/components/common/Input.vue';
import SpinnerIcon from '@/assets/svgs/Spinner.svg';

const router = useRouter();
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
        router.push({ path: '/' });
    } catch (err: unknown) {
        setFromError(err);

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
            <div
                class="bg-white p-6 sm:p-8 shadow-sm border border-gray-100 dark:border-gray-700/60 dark:bg-gray-800 rounded-2xl">
                <h2 class="text-2xl font-bold text-center text-gray-900 dark:text-gray-100">Create an account</h2>
                <p class="mt-1 text-sm text-center text-gray-500 dark:text-gray-400">Enter your details to sign up</p>

                <form class="mt-6 space-y-4" @submit.prevent="register">
                    <InputComponent v-model="name" :error-str="errors.name" name="name" label="Full Name"
                        placeholder="John Doe" autocomplete="name" />

                    <InputComponent v-model="username" :error-str="errors.username" name="username" label="Username"
                        placeholder="johndoe" autocomplete="username" />

                    <InputComponent v-model="email" :error-str="errors.email" name="email" type="email"
                        label="Email Address" placeholder="john@example.com" autocomplete="email" />

                    <InputComponent v-model="password" :error-str="errors.password" type="password" name="password"
                        label="Password" placeholder="••••••••" autocomplete="new-password" />

                    <InputComponent v-model="confirmPassword" :error-str="confirmPasswordError" type="password"
                        name="confirm_password" label="Confirm Password" placeholder="••••••••"
                        autocomplete="new-password" />

                    <p v-show="formError" class="p-1 text-xs font-medium text-red-500 dark:text-red-400">{{ formError }}
                    </p>

                    <div class="pt-2">
                        <button :disabled="isSending" type="submit"
                            :class="{ 'opacity-70 cursor-not-allowed': isSending }"
                            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium tracking-wide text-white bg-blue-600 rounded-lg transition-all duration-200 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 active:scale-[0.99]">

                            <SpinnerIcon v-if="isSending"  />
                            <span v-if="isSending">Creating account...</span>
                            <span v-else>Sign up</span>
                        </button>
                    </div>

                    <p class="text-sm text-center text-gray-600 dark:text-gray-400 pt-2">
                        Already have an account?
                        <RouterLink to="sign-in"
                            class="ml-1 font-medium text-blue-600 hover:underline dark:text-blue-400 whitespace-nowrap">
                            Log in
                        </RouterLink>
                    </p>
                </form>
            </div>
        </div>
    </div>
</template>