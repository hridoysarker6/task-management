<script setup>
import axios from 'axios';
import { ref, watch } from 'vue';

// Search-as-you-type picker for a task (by key like ECP-4, or words in the title).
const props = defineProps({
    projectId: { type: Number, default: null },
    types: { type: String, default: null }, // comma separated, e.g. "epic"
    exclude: { type: Number, default: null },
    placeholder: { type: String, default: 'Search by key or title…' },
});
const emit = defineEmits(['pick']);

const q = ref('');
const results = ref([]);
const open = ref(false);
const loading = ref(false);
let timer;
let seq = 0;

const search = async () => {
    const mine = ++seq;
    loading.value = true;
    try {
        const { data } = await axios.get(route('tasks.search'), {
            params: { q: q.value, project_id: props.projectId || undefined, types: props.types || undefined, exclude: props.exclude || undefined },
        });
        if (mine === seq) results.value = data; // ignore answers that arrive late
    } finally {
        if (mine === seq) loading.value = false;
    }
};

watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 250);
});

const focus = () => {
    open.value = true;
    search();
};
// Close after a beat so a click on a result is not swallowed by the input losing focus.
const closeSoon = () => setTimeout(() => (open.value = false), 150);
const pick = (task) => {
    emit('pick', task);
    q.value = '';
    open.value = false;
};
</script>

<template>
    <div class="relative">
        <input
            v-model="q"
            type="search"
            :placeholder="placeholder"
            role="combobox"
            :aria-expanded="open"
            aria-autocomplete="list"
            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            @focus="focus"
            @blur="closeSoon"
            @keydown.escape="open = false"
        />
        <ul v-if="open" class="absolute z-30 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg" role="listbox">
            <li v-if="loading && !results.length" class="px-3 py-2 text-gray-400">Searching…</li>
            <li v-else-if="!results.length" class="px-3 py-2 text-gray-400">No matching tasks.</li>
            <li v-for="t in results" :key="t.id" role="option">
                <button type="button" class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-indigo-50" @mousedown.prevent="pick(t)">
                    <span class="font-mono text-xs font-semibold text-indigo-700">{{ t.key }}</span>
                    <span class="truncate">{{ t.title }}</span>
                    <span class="ml-auto shrink-0 text-xs text-gray-400">{{ t.status }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
