<script setup>
import Modal from '@/Components/Modal.vue';
import Btn from '@/Components/UI/Btn.vue';

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
            <h2 class="text-lg font-semibold text-gray-900">{{ title }}</h2>
            <p v-if="message" class="mt-2 text-sm text-gray-600">{{ message }}</p>
            <slot />
            <div class="mt-6 flex justify-end gap-2">
                <Btn variant="secondary" @click="$emit('cancel')">Cancel</Btn>
                <Btn :variant="danger ? 'danger' : 'primary'" :loading="loading" @click="$emit('confirm')">
                    {{ confirmText }}
                </Btn>
            </div>
        </div>
    </Modal>
</template>
