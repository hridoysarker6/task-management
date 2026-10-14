<script setup>
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import { CalendarDaysIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// A task on the board: key, title, type, priority, labels, epic, points, due date (red when overdue) and assignee.
const props = defineProps({
    task: { type: Object, required: true },
    showEffort: { type: Boolean, default: true },
});

const due = computed(() => (props.task.due_date ? new Date(props.task.due_date + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : null));
const priorityDot = { 1: 'bg-gray-300', 2: 'bg-blue-400', 3: 'bg-yellow-400', 4: 'bg-orange-500', 5: 'bg-red-600' };
const priorityName = { 1: 'Lowest', 2: 'Low', 3: 'Medium', 4: 'High', 5: 'Highest' };
</script>

<template>
    <article class="cursor-grab rounded-lg border border-gray-200 bg-white p-2.5 shadow-sm hover:border-indigo-300 active:cursor-grabbing" :data-task="task.key">
        <div class="flex items-center gap-2 text-xs">
            <TaskBits kind="type" :value="task.type" />
            <Link :href="route('tasks.show', task.key)" class="font-mono font-semibold text-indigo-700 hover:underline" draggable="false">{{ task.key }}</Link>
            <span :class="['ml-auto h-2.5 w-2.5 rounded-full', priorityDot[task.priority]]" :title="`${priorityName[task.priority]} priority`" :aria-label="`${priorityName[task.priority]} priority`" />
        </div>

        <Link :href="route('tasks.show', task.key)" class="mt-1.5 block text-sm font-medium leading-snug text-gray-900 hover:text-indigo-600" draggable="false">
            <span class="line-clamp-3">{{ task.title }}</span>
        </Link>

        <div v-if="task.labels?.length" class="mt-2 flex flex-wrap gap-1">
            <span v-for="l in task.labels.slice(0, 3)" :key="l.id" class="rounded-full px-1.5 py-0.5 text-[10px] font-medium text-white" :style="{ backgroundColor: l.color }">{{ l.name }}</span>
            <span v-if="task.labels.length > 3" class="text-[10px] text-gray-400">+{{ task.labels.length - 3 }}</span>
        </div>

        <p v-if="task.epic" class="mt-2 truncate rounded bg-purple-50 px-1.5 py-0.5 text-[11px] text-purple-700" :title="task.epic.title">{{ task.epic.key }} · {{ task.epic.title }}</p>

        <div class="mt-2 flex items-center gap-2 text-xs text-gray-500">
            <span v-if="due" :class="['inline-flex items-center gap-1', task.overdue ? 'font-medium text-red-600' : '']" :title="task.overdue ? 'Overdue' : 'Due date'">
                <CalendarDaysIcon class="h-3.5 w-3.5" /> {{ due }}
            </span>
            <span v-if="showEffort && task.story_points !== null" class="rounded-full bg-gray-100 px-1.5 py-0.5 font-medium text-gray-600" title="Story points">{{ task.story_points }}</span>
            <Avatar v-if="task.assignee" :name="task.assignee.name" size="sm" class="ml-auto" />
        </div>
    </article>
</template>
