import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

// The task side panel's state, shared by everything that can open it (board cards, list rows, search...).
// `host` is true while a layout that renders the panel is on screen; without one, opening a task is a normal page visit.
const state = reactive({ key: null, host: false, openedPath: null });

const syncUrl = (key) => {
    try {
        const url = new URL(window.location.href);
        key ? url.searchParams.set('task', key) : url.searchParams.delete('task');
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    } catch {
        /* the address bar is a nicety, never a requirement */
    }
};

export function openTask(key) {
    if (!state.host) {
        router.visit(route('tasks.show', key));
        return;
    }
    state.openedPath = window.location.pathname;
    state.key = key;
    syncUrl(key);
}

export function closeTask() {
    state.key = null;
    syncUrl(null);
}

export function useTaskPanel() {
    return { state, openTask, closeTask };
}
