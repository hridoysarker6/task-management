<script setup>
import { PlusIcon } from '@heroicons/vue/24/outline';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { nextTick, ref } from 'vue';

/**
 * "+ Add task" at the foot of a column or list: type a title, press Enter, and the task is created right there;
 * the box stays open for the next one. @name assigns, !3 sets the priority, #label adds a label. Esc closes it.
 */
const props = defineProps({
    projectId: { type: Number, required: true },
    statusId: { type: Number, default: null }, // a board column
    sprintId: { type: Number, default: null }, // a sprint list
    label: { type: String, default: 'Add task' },
    dark: { type: Boolean, default: false },
});

const open = ref(false);
const text = ref('');
const saving = ref(false);
const error = ref('');
const done = ref('');
const input = ref(null);

const show = async () => {
    open.value = true;
    await nextTick();
    input.value?.focus();
};
const close = () => {
    open.value = false;
    text.value = '';
    error.value = '';
};

const submit = async () => {
    const line = text.value.trim();
    if (!line || saving.value) return;

    saving.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(route('tasks.quick'), { project_id: props.projectId, text: line, status_id: props.statusId, sprint_id: props.sprintId });
        text.value = '';
        done.value = data.triage ? `${data.key} sent for triage` : `${data.key} added`;
        setTimeout(() => (done.value = ''), 2500);
        router.reload({ preserveScroll: true, preserveState: true });
    } catch (e) {
        const errors = e.response?.data?.errors ?? {};
        error.value = e.response?.status === 403 ? 'You cannot add tasks here.' : (Object.values(errors)[0]?.[0] ?? 'The task could not be added.');
    } finally {
        saving.value = false;
        await nextTick();
        input.value?.focus();
    }
};
</script>

<template>
    <div class="no-drag mt-2">
        <button v-if="!open" type="button" :class="['flex w-full items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm transition', dark ? 'text-gray-300 hover:bg-white/10' : 'text-gray-500 hover:bg-white hover:text-violet-700 hover:shadow-sm']" @click="show">
            <PlusIcon class="h-4 w-4" /> {{ label }}
        </button>

        <div v-else class="rounded-lg bg-white p-2 shadow-md ring-1 ring-violet-300">
            <input
                ref="input"
                v-model="text"
                type="text"
                maxlength="400"
                placeholder="What needs doing?"
                aria-label="New task title"
                :disabled="saving"
                class="w-full border-0 p-1 text-sm shadow-none focus:ring-0"
                @keydown.enter.prevent="submit"
                @keydown.esc.stop.prevent="close"
            />
            <p v-if="error" role="alert" class="px-1 pb-1 text-xs font-medium text-rose-600">{{ error }}</p>
            <p v-else class="px-1 pb-1 text-[11px] text-gray-400"><kbd class="rounded bg-gray-100 px-1">Enter</kbd> add · <kbd class="rounded bg-gray-100 px-1">Esc</kbd> close · <span class="whitespace-nowrap">@name !3 #label</span></p>
        </div>

        <p v-if="done" role="status" class="px-2 pt-1 text-xs font-medium text-emerald-600">{{ done }}</p>
    </div>
</template>
