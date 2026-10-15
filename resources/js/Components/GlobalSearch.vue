<script setup>
import Skeleton from '@/Components/UI/Skeleton.vue';
import { BriefcaseIcon, MagnifyingGlassIcon, UserIcon } from '@heroicons/vue/24/outline';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

// The Ctrl/Cmd+K palette: tasks by key or title, projects and people. Arrow keys move, Enter opens, Esc closes.
const props = defineProps({ show: Boolean });
const emit = defineEmits(['close']);

const q = ref('');
const loading = ref(false);
const results = ref({ tasks: [], projects: [], people: [] });
const active = ref(0);
const input = ref(null);
let timer;
let sequence = 0;

// One flat list so the arrow keys walk across the groups.
const flat = computed(() => [
    ...results.value.tasks.map((r) => ({ ...r, kind: 'task' })),
    ...results.value.projects.map((r) => ({ ...r, kind: 'project' })),
    ...results.value.people.map((r) => ({ ...r, kind: 'person' })),
]);
const groups = computed(() => [
    { key: 'tasks', label: 'Tasks', items: flat.value.filter((r) => r.kind === 'task') },
    { key: 'projects', label: 'Projects', items: flat.value.filter((r) => r.kind === 'project') },
    { key: 'people', label: 'People', items: flat.value.filter((r) => r.kind === 'person') },
].filter((g) => g.items.length));
const indexOf = (item) => flat.value.indexOf(item);

watch(
    () => props.show,
    async (open) => {
        if (open) {
            q.value = '';
            results.value = { tasks: [], projects: [], people: [] };
            active.value = 0;
            await nextTick();
            input.value?.focus();
        }
    },
);

const search = async () => {
    const mine = ++sequence;
    if (q.value.trim().length < 2) {
        results.value = { tasks: [], projects: [], people: [] };
        loading.value = false;
        return;
    }
    loading.value = true;
    try {
        const { data } = await axios.get(route('search'), { params: { q: q.value.trim() } });
        if (mine === sequence) {
            results.value = data;
            active.value = 0;
        }
    } catch {
        if (mine === sequence) results.value = { tasks: [], projects: [], people: [] };
    } finally {
        if (mine === sequence) loading.value = false;
    }
};
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 200);
});
onBeforeUnmount(() => clearTimeout(timer));

const go = (item) => {
    emit('close');
    if (item) router.visit(item.url);
};
const onKey = (e) => {
    if (e.key === 'Escape') emit('close');
    else if (e.key === 'ArrowDown') (e.preventDefault(), (active.value = Math.min(flat.value.length - 1, active.value + 1)));
    else if (e.key === 'ArrowUp') (e.preventDefault(), (active.value = Math.max(0, active.value - 1)));
    else if (e.key === 'Enter') (e.preventDefault(), go(flat.value[active.value]));
};

</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-start justify-center bg-gray-900/40 px-4 pt-[12vh]" role="dialog" aria-modal="true" aria-label="Search" @mousedown.self="emit('close')">
        <div class="w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-2xl" @keydown="onKey">
            <div class="flex items-center gap-2 border-b border-gray-100 px-4">
                <MagnifyingGlassIcon class="h-5 w-5 text-gray-400" />
                <input ref="input" v-model="q" type="search" autocomplete="off" placeholder="Search tasks, projects and people…" aria-label="Search" class="w-full border-0 bg-transparent py-3.5 text-sm focus:ring-0" />
                <kbd class="hidden rounded border border-gray-200 px-1.5 text-xs text-gray-400 sm:block">Esc</kbd>
            </div>

            <div class="max-h-[55vh] overflow-y-auto" role="listbox">
                <div v-if="loading" class="p-4"><Skeleton :lines="3" /></div>
                <p v-else-if="q.trim().length < 2" class="px-4 py-8 text-center text-sm text-gray-400">Type at least two letters, or a task key such as ECP-12.</p>
                <p v-else-if="!flat.length" class="px-4 py-8 text-center text-sm text-gray-400">Nothing found for “{{ q }}”.</p>

                <template v-else>
                    <div v-for="g in groups" :key="g.key" class="py-1">
                        <p class="px-4 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ g.label }}</p>
                        <button
                            v-for="item in g.items"
                            :key="item.url"
                            type="button"
                            role="option"
                            :aria-selected="indexOf(item) === active"
                            :class="['flex w-full items-center gap-3 px-4 py-2 text-left text-sm', indexOf(item) === active ? 'bg-indigo-50' : 'hover:bg-gray-50']"
                            @mousemove="active = indexOf(item)"
                            @click="go(item)"
                        >
                            <template v-if="item.kind === 'task'"><span class="font-mono text-xs font-semibold text-indigo-700">{{ item.key }}</span><span class="min-w-0 flex-1 truncate text-gray-900">{{ item.title }}</span><span class="shrink-0 text-xs text-gray-400">{{ item.status }}</span></template>
                            <template v-else-if="item.kind === 'project'"><BriefcaseIcon class="h-4 w-4 text-gray-400" /><span class="font-mono text-xs font-semibold text-indigo-700">{{ item.key }}</span><span class="min-w-0 flex-1 truncate text-gray-900">{{ item.name }}</span></template>
                            <template v-else><UserIcon class="h-4 w-4 text-gray-400" /><span class="min-w-0 flex-1 truncate text-gray-900">{{ item.name }}</span><span class="shrink-0 text-xs text-gray-400">{{ item.role }}</span></template>
                        </button>
                    </div>
                </template>
            </div>

            <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-gray-100 bg-gray-50 px-4 py-2 text-xs text-gray-400">
                <span><kbd class="rounded border border-gray-200 bg-white px-1">↑</kbd> <kbd class="rounded border border-gray-200 bg-white px-1">↓</kbd> to move</span>
                <span><kbd class="rounded border border-gray-200 bg-white px-1">Enter</kbd> to open</span>
                <span class="hidden sm:inline"><kbd class="rounded border border-gray-200 bg-white px-1">C</kbd> create a task · <kbd class="rounded border border-gray-200 bg-white px-1">/</kbd> search</span>
            </div>
        </div>
    </div>
</template>
