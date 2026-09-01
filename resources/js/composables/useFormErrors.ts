import { reactive, ref } from 'vue';
import { isAxiosError } from 'axios';
import type { ApiErrorBody } from '@/types/api';

type ErrorMap = Record<string, string>;

/**
 * Maps a Laravel validation error response onto per-field messages, deduping
 * the identical try/catch blocks the forms used to carry.
 *
 * @param fields the form field names that can receive a validation error
 */
export function useFormErrors<F extends string>(fields: readonly F[]) {
    const errors = reactive<ErrorMap>(
        Object.fromEntries(fields.map((field) => [field, ''])),
    ) as Record<F, string> & ErrorMap;
    const formError = ref('');

    function clear(): void {
        for (const field of fields) {
            errors[field] = '';
        }
        formError.value = '';
    }

    function setFromError(err: unknown): void {
        clear();

        if (isAxiosError<ApiErrorBody>(err) && err.response && err.response.status < 500) {
            const body = err.response.data;
            if (body?.errors) {
                for (const field of fields) {
                    const message = body.errors[field]?.[0];
                    if (message) {
                        errors[field] = message;
                    }
                }
            } else {
                formError.value = body?.message ?? 'Something went wrong!';
            }
        } else {
            formError.value = 'Something went wrong!';
        }
    }

    return { errors, formError, clear, setFromError };
}
