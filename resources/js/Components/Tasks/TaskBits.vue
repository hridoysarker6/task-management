<script setup>
import { BookmarkIcon, BugAntIcon, CheckCircleIcon, ListBulletIcon, RectangleStackIcon } from '@heroicons/vue/24/outline';
import { computed } from 'vue';

// Small visual pieces of a task: its type icon, priority and status chips.
// Use <TaskBits kind="type" :value="task.type" /> etc.
const props = defineProps({
    kind: { type: String, required: true }, // type | priority | status
    value: { type: [String, Number, Object], default: null },
});

const types = {
    epic: { icon: RectangleStackIcon, color: 'text-purple-600', label: 'Epic' },
    story: { icon: BookmarkIcon, color: 'text-green-600', label: 'Story' },
    task: { icon: CheckCircleIcon, color: 'text-blue-600', label: 'Task' },
    bug: { icon: BugAntIcon, color: 'text-red-600', label: 'Bug' },
    subtask: { icon: ListBulletIcon, color: 'text-sky-600', label: 'Sub-task' },
};
const priorities = {
    1: { label: 'Lowest', cls: 'bg-gray-100 text-gray-600' },
    2: { label: 'Low', cls: 'bg-blue-50 text-blue-700' },
    3: { label: 'Medium', cls: 'bg-yellow-50 text-yellow-800' },
    4: { label: 'High', cls: 'bg-orange-100 text-orange-800' },
    5: { label: 'Highest', cls: 'bg-red-100 text-red-700' },
};

const type = computed(() => types[props.value] ?? types.task);
const priority = computed(() => priorities[props.value] ?? priorities[3]);
</script>

<template>
    <span v-if="kind === 'type'" class="inline-flex items-center gap-1 text-xs text-gray-600" :title="type.label">
        <component :is="type.icon" :class="['h-4 w-4', type.color]" /> <span class="hidden sm:inline">{{ type.label }}</span>
    </span>
    <span v-else-if="kind === 'priority'" :class="['inline-flex rounded-full px-2 py-0.5 text-xs font-medium', priority.cls]">{{ priority.label }}</span>
    <span v-else-if="kind === 'status' && value" class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-2 py-0.5 text-xs font-medium text-gray-700">
        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: value.color }" /> {{ value.name }}
    </span>
</template>
