<script setup>
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/UI/Btn.vue';
import { ExclamationTriangleIcon, QuestionMarkCircleIcon } from '@heroicons/vue/24/outline';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Are you sure?' },
    message: { type: String, default: '' },
    confirmText: { type: String, default: 'Confirm' },
    danger: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});
defineEmits(['confirm', 'cancel']);
</script>

<template>
    <Modal :show="show" max-width="md" @close="$emit('cancel')">
        <div class="p-6">
            <div class="flex items-start gap-4">
                <span :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', danger ? 'bg-rose-100 text-rose-600' : 'bg-violet-100 text-violet-600']">
                    <component :is="danger ? ExclamationTriangleIcon : QuestionMarkCircleIcon" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold text-gray-900">{{ title }}</h2>
                    <p v-if="message" class="mt-1.5 text-sm text-gray-600">{{ message }}</p>
                    <slot />
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <Btn variant="secondary" @click="$emit('cancel')">Cancel</Btn>
                <Btn :variant="danger ? 'danger' : 'primary'" :loading="loading" @click="$emit('confirm')">{{ confirmText }}</Btn>
            </div>
        </div>
    </Modal>
</template>
