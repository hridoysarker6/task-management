<script setup>
import { CheckCircleIcon, ExclamationCircleIcon, ExclamationTriangleIcon, XMarkIcon } from '@heroicons/vue/24/solid';
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

// Toasts: success, error and warning messages from the server slide in at the top right and dismiss themselves.
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

const looks = {
    success: { icon: CheckCircleIcon, tint: 'text-emerald-500', bar: 'bg-emerald-500' },
    error: { icon: ExclamationCircleIcon, tint: 'text-rose-500', bar: 'bg-rose-500' },
    warning: { icon: ExclamationTriangleIcon, tint: 'text-amber-500', bar: 'bg-amber-500' },
};
</script>

<template>
    <div class="pointer-events-none fixed right-4 top-4 z-[60] flex w-full max-w-sm flex-col gap-2" aria-live="polite">
        <transition-group enter-active-class="transition duration-300 ease-out" enter-from-class="translate-x-6 opacity-0" leave-active-class="transition duration-200" leave-to-class="translate-x-6 opacity-0" move-class="transition duration-200">
            <div v-for="t in toasts" :key="t.id" class="pointer-events-auto relative overflow-hidden rounded-xl border border-gray-200 bg-white/95 shadow-lg backdrop-blur" role="status">
                <div class="flex items-start gap-3 px-4 py-3">
                    <component :is="looks[t.type].icon" :class="['mt-0.5 h-5 w-5 shrink-0', looks[t.type].tint]" />
                    <p class="flex-1 text-sm text-gray-800">{{ t.message }}</p>
                    <button type="button" class="rounded-md p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="Dismiss" @click="dismiss(t.id)"><XMarkIcon class="h-4 w-4" /></button>
                </div>
                <div :class="['absolute bottom-0 left-0 h-0.5 animate-shrink', looks[t.type].bar]" />
            </div>
        </transition-group>
    </div>
</template>
