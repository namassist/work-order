import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Checks the current user's permissions shared by HandleInertiaRequests.
 * UI hint only: the server enforces every permission again.
 */
export function useCan() {
    const page = usePage();
    const permissions = computed(
        () => new Set(page.props.auth.permissions ?? []),
    );

    return (permission: string): boolean => permissions.value.has(permission);
}
