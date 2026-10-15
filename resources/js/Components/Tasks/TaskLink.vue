<script setup>
import { openTask } from '@/Composables/useTaskPanel';

// A link to a task. A plain click opens it in the side panel; ctrl/cmd/shift-click or middle click opens the full page as usual.
const props = defineProps({
    taskKey: { type: String, required: true },
});

const onClick = (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    openTask(props.taskKey);
};
</script>

<template>
    <a :href="route('tasks.show', taskKey)" draggable="false" @click="onClick"><slot>{{ taskKey }}</slot></a>
</template>
