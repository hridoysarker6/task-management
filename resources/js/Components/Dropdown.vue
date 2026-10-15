<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    align: { type: String, default: 'right' }, // left | right
    width: { type: String, default: '48' },
    contentClasses: { type: String, default: 'py-1.5 bg-white' },
});

const open = ref(false);

const closeOnEscape = (e) => {
    if (open.value && e.key === 'Escape') open.value = false;
};
onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape));

const widthClass = computed(() => ({ 48: 'w-48', 56: 'w-56', 64: 'w-64' })[String(props.width)] ?? 'w-48');
const alignClass = computed(() => (props.align === 'left' ? 'origin-top-left left-0' : 'origin-top-right right-0'));
</script>

<template>
    <div class="relative">
        <div @click="open = !open"><slot name="trigger" :open="open" /></div>

        <div v-show="open" class="fixed inset-0 z-40" @click="open = false" />

        <Transition enter-active-class="transition ease-out duration-150" enter-from-class="scale-95 opacity-0" enter-to-class="scale-100 opacity-100" leave-active-class="transition ease-in duration-100" leave-from-class="scale-100 opacity-100" leave-to-class="scale-95 opacity-0">
            <div v-show="open" :class="['absolute z-50 mt-2 rounded-xl shadow-lg', widthClass, alignClass]" style="display: none" @click="open = false">
                <div :class="['overflow-hidden rounded-xl ring-1 ring-gray-900/5', contentClasses]">
                    <slot name="content" />
                </div>
            </div>
        </Transition>
    </div>
</template>
