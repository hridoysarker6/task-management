<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Edit one field where it is shown. Click (or Enter on it) to edit; Enter or leaving the field saves, Esc cancels.
 * `save` receives the new value and returns a promise: if it rejects, the message appears under the field.
 * Selects save as soon as a choice is made. Without `can`, the value is just displayed.
 */
const props = defineProps({
    modelValue: { default: null },
    type: { type: String, default: 'text' }, // text | number | date | select
    options: { type: Array, default: () => [] }, // select: [{ value, label }]
    can: { type: Boolean, default: false },
    save: { type: Function, required: true },
    label: { type: String, required: true }, // for screen readers: "Title of ECP-12"
    placeholder: { type: String, default: '' },
    maxlength: { type: Number, default: 255 },
    min: { type: [Number, String], default: null },
    step: { type: [Number, String], default: null },
    taskKey: { type: String, default: null }, // lets a keyboard shortcut start editing this field
    field: { type: String, default: null },
    widthClass: { type: String, default: '' },
});

const editing = ref(false);
const draft = ref('');
const error = ref('');
const saving = ref(false);
const control = ref(null);
const trigger = ref(null);
let flashTimer;

const start = async () => {
    if (!props.can || editing.value) return;
    error.value = '';
    draft.value = props.type === 'select' ? props.modelValue : (props.modelValue ?? '');
    editing.value = true;
    await nextTick();
    control.value?.focus();
    if (props.type === 'text' || props.type === 'number') control.value?.select?.();
    if (props.type === 'select') {
        try {
            control.value?.showPicker?.(); // open the list at once; some browsers need a real click and refuse
        } catch {
            /* the select is focused anyway */
        }
    }
};

const normalise = (v) => {
    if (props.type === 'text') return String(v ?? '').trim();
    if (v === '' || v === null || v === undefined) return null;
    return props.type === 'number' ? Number(v) : v;
};
const same = (a, b) => String(a ?? '') === String(b ?? '');

const finish = async () => {
    editing.value = false;
    await nextTick();
    trigger.value?.focus();
};

const flash = (message) => {
    error.value = message;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => (error.value = ''), 4500);
};

const commit = async (source) => {
    if (!editing.value || saving.value) return;

    const value = normalise(draft.value);
    if (props.type === 'text' && value === '') {
        // An empty title is not a title: put the old one back.
        editing.value = false;
        return;
    }
    if (same(value, props.modelValue)) {
        await finish();
        return;
    }

    saving.value = true;
    try {
        await props.save(value);
        await finish();
    } catch (e) {
        if (source === 'enter') {
            error.value = e.message; // stay open so nothing typed is lost
            control.value?.focus();
        } else {
            editing.value = false;
            flash(e.message);
        }
    } finally {
        saving.value = false;
    }
};

const cancel = async () => {
    error.value = '';
    await finish();
};

// A keyboard shortcut elsewhere (for example "A" for assign) can ask this field to start editing.
const onRequest = (e) => {
    if (props.taskKey && e.detail?.key === props.taskKey && e.detail?.field === props.field) start();
};
onMounted(() => window.addEventListener('task-inline', onRequest));
onBeforeUnmount(() => {
    window.removeEventListener('task-inline', onRequest);
    clearTimeout(flashTimer);
});

const onSelect = () => commit('select');
</script>

<template>
    <span v-if="!can" :class="widthClass"><slot name="display"><span>{{ modelValue }}</span></slot></span>

    <span v-else class="no-drag relative inline-block max-w-full align-middle" :class="widthClass">
        <button
            v-if="!editing"
            ref="trigger"
            type="button"
            class="block max-w-full rounded-md px-1 py-0.5 -mx-1 text-left transition hover:bg-violet-50 hover:ring-1 hover:ring-violet-200 focus-visible:bg-violet-50"
            :aria-label="`${label}: edit`"
            @click.stop.prevent="start"
        >
            <slot name="display"><span>{{ modelValue }}</span></slot>
        </button>

        <template v-else>
            <select
                v-if="type === 'select'"
                ref="control"
                v-model="draft"
                :aria-label="label"
                :disabled="saving"
                class="max-w-full py-1 pl-2 pr-7 text-sm"
                @change="onSelect"
                @blur="cancel"
                @keydown.esc.stop.prevent="cancel"
                @click.stop
            >
                <option v-for="o in options" :key="String(o.value)" :value="o.value" :disabled="o.disabled">{{ o.label }}</option>
            </select>
            <input
                v-else
                ref="control"
                v-model="draft"
                :type="type"
                :aria-label="label"
                :placeholder="placeholder"
                :maxlength="type === 'text' ? maxlength : undefined"
                :min="min ?? undefined"
                :step="step ?? undefined"
                :disabled="saving"
                class="w-full min-w-[7rem] px-2 py-1 text-sm"
                @keydown.enter.prevent="commit('enter')"
                @keydown.esc.stop.prevent="cancel"
                @blur="commit('blur')"
                @click.stop
            />
        </template>

        <span v-if="saving" class="absolute -right-5 top-1.5 h-3.5 w-3.5 animate-spin rounded-full border-2 border-violet-300 border-t-violet-600" aria-hidden="true" />
        <span v-if="error" role="alert" class="absolute left-0 top-full z-30 mt-1 w-56 rounded-lg bg-rose-600 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg">{{ error }}</span>
    </span>
</template>
