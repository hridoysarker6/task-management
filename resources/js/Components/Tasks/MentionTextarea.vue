<script setup>
import { computed, nextTick, ref } from 'vue';

// A textarea that suggests team members after "@" and writes them as @[Name](m:ID).
const model = defineModel({ type: String, default: '' });
const props = defineProps({
    people: { type: Array, default: () => [] }, // [{ id, name }]
    placeholder: { type: String, default: '' },
    rows: { type: Number, default: 3 },
    id: { type: String, default: undefined },
});

const el = ref(null);
const query = ref(null); // text after the "@", or null when no mention is being typed
const active = ref(0);

const matches = computed(() => {
    if (query.value === null) return [];
    const q = query.value.toLowerCase();
    return props.people.filter((p) => p.name.toLowerCase().includes(q)).slice(0, 6);
});

const detect = () => {
    const pos = el.value.selectionStart;
    const before = model.value.slice(0, pos);
    const m = before.match(/(?:^|\s)@([^\s@\[\]]{0,30})$/);
    query.value = m ? m[1] : null;
    active.value = 0;
};

const choose = async (person) => {
    const pos = el.value.selectionStart;
    const before = model.value.slice(0, pos).replace(/@([^\s@\[\]]{0,30})$/, '');
    const token = `@[${person.name.replace(/[\]\[]/g, '')}](m:${person.id}) `;
    model.value = before + token + model.value.slice(pos);
    query.value = null;
    await nextTick();
    el.value.focus();
    const caret = (before + token).length;
    el.value.setSelectionRange(caret, caret);
};

const onKey = (e) => {
    if (!matches.value.length) return;
    if (e.key === 'ArrowDown') (e.preventDefault(), (active.value = (active.value + 1) % matches.value.length));
    else if (e.key === 'ArrowUp') (e.preventDefault(), (active.value = (active.value - 1 + matches.value.length) % matches.value.length));
    else if (e.key === 'Enter' || e.key === 'Tab') (e.preventDefault(), choose(matches.value[active.value]));
    else if (e.key === 'Escape') query.value = null;
};
</script>

<template>
    <div class="relative">
        <textarea
            :id="id"
            ref="el"
            v-model="model"
            :rows="rows"
            :placeholder="placeholder"
            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            @input="detect"
            @click="detect"
            @keydown="onKey"
            @blur="query = null"
        />
        <ul v-if="matches.length" class="absolute z-30 mt-1 w-64 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg" role="listbox" aria-label="Mention a teammate">
            <li v-for="(p, i) in matches" :key="p.id" role="option" :aria-selected="i === active">
                <button type="button" :class="['block w-full px-3 py-1.5 text-left', i === active ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-gray-50']" @mousedown.prevent="choose(p)">{{ p.name }}</button>
            </li>
        </ul>
    </div>
</template>
