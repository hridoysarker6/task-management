<script setup>
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { computed } from 'vue';

// A dialog built on Headless UI: focus is trapped inside, Esc and a click outside close it, and it eases in and out.
const props = defineProps({
    show: { type: Boolean, default: false },
    maxWidth: { type: String, default: '2xl' }, // sm | md | lg | xl | 2xl | 3xl
    closeable: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);

const close = () => {
    if (props.closeable) emit('close');
};

const maxWidthClass = computed(
    () =>
        ({
            sm: 'sm:max-w-sm',
            md: 'sm:max-w-md',
            lg: 'sm:max-w-lg',
            xl: 'sm:max-w-xl',
            '2xl': 'sm:max-w-2xl',
            '3xl': 'sm:max-w-3xl',
        })[props.maxWidth] ?? 'sm:max-w-2xl',
);
</script>

<template>
    <TransitionRoot as="template" :show="show" appear>
        <Dialog class="relative z-50" @close="close">
            <TransitionChild as="template" enter="ease-out duration-200" enter-from="opacity-0" enter-to="opacity-100" leave="ease-in duration-150" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm" aria-hidden="true" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 sm:items-center">
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-200"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-150"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    >
                        <DialogPanel :class="['w-full transform overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-gray-900/5 transition-all', maxWidthClass]">
                            <slot />
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
