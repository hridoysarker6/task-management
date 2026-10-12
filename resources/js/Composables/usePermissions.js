import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Permissions of the signed-in person in the current company (shared by the server on every page).
 * UI-only convenience: the server still authorizes every request.
 */
export function usePermissions() {
    const page = usePage();
    const permissions = computed(() => new Set(page.props.auth?.permissions ?? []));

    const can = (name) => permissions.value.has(name);
    const canAny = (...names) => names.some(can);
    const canAll = (...names) => names.every(can);

    return { permissions, can, canAny, canAll };
}
