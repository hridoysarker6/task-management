import { usePage } from '@inertiajs/vue3';

const allowed = (value) => {
    const granted = usePage().props.auth?.permissions ?? [];
    const names = Array.isArray(value) ? value : [value];

    return names.some((name) => granted.includes(name));
};

/**
 * Registers `$can('task.assign')` for templates and the `v-can` directive:
 *   <button v-can="'task.assign'">   (hidden unless the permission is held)
 *   <button v-can="['task.edit_any', 'task.edit_own']">   (any of them)
 */
export default {
    install(app) {
        app.config.globalProperties.$can = allowed;

        const apply = (el, binding) => {
            const ok = allowed(binding.value);
            el.hidden = !ok;
            el.style.display = ok ? '' : 'none';
        };

        app.directive('can', { mounted: apply, updated: apply });
    },
};
