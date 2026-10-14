<script setup>
import { ChevronDownIcon } from '@heroicons/vue/24/outline';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// A button that opens a checklist: pick any number of values. Click outside or press Escape to close.
const model = defineModel({ type: Array, default: () => [] });
const props = defineProps({
    label: { type: String, required: true },
    options: { type: Array, default: () => [] }, // [{ value, label, color? }]
    align: { type: String, default: 'left' },
});

const open = ref(false);
const root = ref(null);

const count = computed(() => model.value.length);
const toggle = (value) => {
    const next = model.value.includes(value) ? model.value.filter((v) => v !== value) : [...model.value, value];
    model.value = next;
};

const onDocClick = (e) => {
    if (root.value && !root.value.contains(e.target)) open.value = false;
};
onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <div ref="root" class="relative" @keydown.escape="open = false">
        <button
            type="button"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :class="['inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm', count ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50']"
            @click="open = !open"
        >
            {{ label }}<span v-if="count" class="rounded-full bg-indigo-600 px-1.5 text-xs text-white">{{ count }}</span>
            <ChevronDownIcon class="h-3.5 w-3.5 text-gray-400" />
        </button>
        <div
            v-if="open"
            role="listbox"
            :aria-label="label"
            :class="['absolute z-30 mt-1 max-h-72 w-56 overflow-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg', align === 'right' ? 'right-0' : 'left-0']"
        >
            <p v-if="!options.length" class="px-3 py-2 text-gray-400">Nothing to choose.</p>
            <label v-for="o in options" :key="String(o.value)" class="flex cursor-pointer items-center gap-2 px-3 py-1.5 hover:bg-gray-50">
                <input type="checkbox" :checked="model.includes(o.value)" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @change="toggle(o.value)" />
                <span v-if="o.color" class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: o.color }" />
                <span class="truncate">{{ o.label }}</span>
            </label>
            <button v-if="count" type="button" class="mt-1 w-full border-t border-gray-100 px-3 py-1.5 text-left text-xs text-gray-500 hover:bg-gray-50" @click="model = []">Clear</button>
        </div>
    </div>
</template>
