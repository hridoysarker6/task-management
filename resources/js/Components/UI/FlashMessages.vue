<script setup>
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const page = usePage();
const toasts = ref([]);
let nextId = 1;

function push(type, message) {
    if (!message) return;
    const id = nextId++;
    toasts.value.push({ id, type, message });
    setTimeout(() => dismiss(id), 5000);
}
function dismiss(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id);
}

watch(
    () => page.props.flash,
    (flash) => {
        push('success', flash?.success);
        push('error', flash?.error);
        push('warning', flash?.warning);
    },
    { immediate: true, deep: true },
);

const styles = {
    success: 'border-green-200 bg-green-50 text-green-800',
    error: 'border-red-200 bg-red-50 text-red-800',
    warning: 'border-yellow-200 bg-yellow-50 text-yellow-800',
};
</script>

<template>
    <div class="pointer-events-none fixed right-4 top-4 z-[60] flex w-full max-w-sm flex-col gap-2" aria-live="polite">
        <transition-group enter-active-class="transition duration-200" enter-from-class="translate-x-4 opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div
                v-for="t in toasts"
                :key="t.id"
                :class="['pointer-events-auto flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg', styles[t.type]]"
                role="status"
            >
                <span>{{ t.message }}</span>
                <button type="button" class="opacity-60 hover:opacity-100" aria-label="Dismiss" @click="dismiss(t.id)">&times;</button>
            </div>
        </transition-group>
    </div>
</template>
