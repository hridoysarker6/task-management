<script setup>
import { onMounted, ref } from 'vue';

// A text field. The look (rounded, soft focus glow) comes from the base styles in app.css.
const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input v-model="model" ref="input" class="block w-full px-3.5 py-2.5" />
</template>
