import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * Keyboard shortcuts for the whole app, and the "selected task" that J / K move through.
 * Pages that list tasks register their keys with `setSelectable`; cards and rows highlight when `isSelected(key)`.
 */

// --- the selected task -----------------------------------------------------------------------------
const selection = reactive({ keys: [], selected: null });

export const setSelectable = (keys) => {
    selection.keys = keys;
    if (selection.selected && !keys.includes(selection.selected)) selection.selected = null;
};
export const clearSelectable = () => {
    selection.keys = [];
    selection.selected = null;
};
export const isSelected = (key) => selection.selected === key;
export const selectedKey = computed(() => selection.selected);
export const selectTask = (key) => (selection.selected = key);

const moveSelection = (delta) => {
    if (!selection.keys.length) return;
    const at = selection.selected ? selection.keys.indexOf(selection.selected) : -1;
    const next = Math.min(selection.keys.length - 1, Math.max(0, at === -1 ? (delta > 0 ? 0 : selection.keys.length - 1) : at + delta));
    selection.selected = selection.keys[next];
    // Bring it into view; the cards and rows carry data-task-key.
    requestAnimationFrame(() => document.querySelector(`[data-task-key="${selection.selected}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
};

// --- the shortcut list (also drives the help sheet) ---------------------------------------------------
export const helpOpen = ref(false);

export const SHORTCUT_GROUPS = [
    {
        title: 'Anywhere',
        items: [
            { keys: ['Ctrl', 'K'], text: 'Search and jump' },
            { keys: ['/'], text: 'Search' },
            { keys: ['C'], text: 'Create a task' },
            { keys: ['?'], text: 'Show this list' },
            { keys: ['Esc'], text: 'Close a dialog or the task panel' },
        ],
    },
    {
        title: 'Lists and boards',
        items: [
            { keys: ['J'], text: 'Next task' },
            { keys: ['K'], text: 'Previous task' },
            { keys: ['Enter'], text: 'Open the selected task' },
            { keys: ['A'], text: 'Assign the selected task' },
            { keys: ['S'], text: 'Change its status' },
            { keys: ['P'], text: 'Change its priority' },
            { keys: ['D'], text: 'Change its due date' },
        ],
    },
    {
        title: 'Go to',
        items: [
            { keys: ['G', 'D'], text: 'Dashboard' },
            { keys: ['G', 'M'], text: 'My work' },
            { keys: ['G', 'P'], text: 'Projects' },
            { keys: ['G', 'T'], text: 'My timesheet' },
            { keys: ['G', 'B'], text: 'Board of this project' },
            { keys: ['G', 'L'], text: 'List of this project' },
        ],
    },
];

// --- the key handler --------------------------------------------------------------------------------------
const typing = (el) => !!el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable);

/**
 * Install the handler. `ctx` gives it what it needs from the layout:
 *   { can(permission), page, openSearch(), toggleSearch(), openCreate() }
 * Returns a function that removes it.
 */
export function installShortcuts(ctx) {
    let goPending = false;
    let goTimer;

    const inline = (field) => selection.selected && window.dispatchEvent(new CustomEvent('task-inline', { detail: { key: selection.selected, field } }));

    const go = (letter) => {
        const project = ctx.page.props.project?.id;
        const target = {
            d: () => router.visit(route('dashboard')),
            m: () => ctx.can('task.view') && router.visit(route('my-work')),
            p: () => ctx.can('project.view') && router.visit(route('projects.index')),
            t: () => ctx.can('worklog.create') && router.visit(route('timesheet')),
            b: () => project && router.visit(route('projects.board', project)),
            l: () => project && router.visit(route('projects.tasks.index', project)),
        }[letter];
        target?.();
    };

    const onKey = (e) => {
        if (!ctx.page.props.currentOrganization) return;

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            ctx.toggleSearch();
            return;
        }

        // Plain keys only count when nobody is typing, no dialog is open and no modifier is held.
        if (e.ctrlKey || e.metaKey || e.altKey || typing(e.target) || document.querySelector('[role="dialog"]')) return;

        const key = e.key.toLowerCase();

        if (goPending) {
            goPending = false;
            clearTimeout(goTimer);
            e.preventDefault();
            go(key);
            return;
        }

        if (e.key === '/') {
            e.preventDefault();
            ctx.openSearch();
        } else if (e.key === '?') {
            e.preventDefault();
            helpOpen.value = true;
        } else if (key === 'c' && ctx.can('task.create')) {
            e.preventDefault();
            ctx.openCreate();
        } else if (key === 'g') {
            goPending = true;
            goTimer = setTimeout(() => (goPending = false), 1200);
        } else if (key === 'j') {
            e.preventDefault();
            moveSelection(1);
        } else if (key === 'k') {
            e.preventDefault();
            moveSelection(-1);
        } else if (key === 'a') {
            inline('assignee');
        } else if (key === 's') {
            inline('status');
        } else if (key === 'p') {
            inline('priority');
        } else if (key === 'd') {
            inline('due');
        } else if (e.key === 'Enter' && selection.selected && document.activeElement === document.body) {
            e.preventDefault();
            ctx.openTask(selection.selected);
        } else if (e.key === 'Escape' && selection.selected) {
            selection.selected = null;
        }
    };

    window.addEventListener('keydown', onKey);

    return () => {
        window.removeEventListener('keydown', onKey);
        clearTimeout(goTimer);
    };
}
