<script setup>
import InlineField from '@/Components/Tasks/InlineField.vue';
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import TaskLink from '@/Components/Tasks/TaskLink.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import { isSelected } from '@/Composables/useShortcuts';
import { assignTask, patchTask } from '@/Composables/useTaskEdit';
import { CalendarDaysIcon } from '@heroicons/vue/24/outline';
import { computed } from 'vue';

// A task on the board: key, title, type, priority, labels, epic, points, due date (red when overdue) and assignee.
// Priority, due date and assignee can be changed right on the card when the person may edit the task.
const props = defineProps({
    task: { type: Object, required: true },
    showEffort: { type: Boolean, default: true },
    assignees: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
});

const can = computed(() => props.task.can ?? { update: false, assign: false });
const due = computed(() => (props.task.due_date ? new Date(props.task.due_date + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : null));
const priorityDot = { 1: 'bg-gray-300', 2: 'bg-blue-400', 3: 'bg-yellow-400', 4: 'bg-orange-500', 5: 'bg-red-600' };
const priorityName = { 1: 'Lowest', 2: 'Low', 3: 'Medium', 4: 'High', 5: 'Highest' };

const assigneeChoices = computed(() => {
    const list = props.assignees.map((p) => ({ value: p.id, label: p.name }));
    const current = props.task.assignee;
    if (current && !list.some((o) => o.value === current.id)) list.unshift({ value: current.id, label: current.name });
    return [{ value: null, label: 'Unassigned' }, ...list];
});
const priorityChoices = computed(() => props.priorities.map((p) => ({ value: p.value, label: p.label })));
</script>

<template>
    <article
        :data-task-key="task.key"
        :class="['cursor-grab rounded-lg border bg-white p-2.5 shadow-sm active:cursor-grabbing', isSelected(task.key) ? 'border-violet-500 ring-2 ring-violet-300' : 'border-gray-200 hover:border-indigo-300']"
    >
        <div class="flex items-center gap-2 text-xs">
            <TaskBits kind="type" :value="task.type" />
            <TaskLink :task-key="task.key" class="font-mono font-semibold text-indigo-700 hover:underline">{{ task.key }}</TaskLink>
            <span class="ml-auto">
                <InlineField :model-value="task.priority" type="select" :options="priorityChoices" :can="can.update" :label="`Priority of ${task.key}`" :save="(v) => patchTask(task.key, { priority: v })" :task-key="task.key" field="priority">
                    <template #display><span :class="['block h-2.5 w-2.5 rounded-full', priorityDot[task.priority]]" :title="`${priorityName[task.priority]} priority`" :aria-label="`${priorityName[task.priority]} priority`" /></template>
                </InlineField>
            </span>
        </div>

        <TaskLink :task-key="task.key" class="mt-1.5 block text-sm font-medium leading-snug text-gray-900 hover:text-indigo-600">
            <span class="line-clamp-3">{{ task.title }}</span>
        </TaskLink>

        <div v-if="task.labels?.length" class="mt-2 flex flex-wrap gap-1">
            <span v-for="l in task.labels.slice(0, 3)" :key="l.id" class="rounded-full px-1.5 py-0.5 text-[10px] font-medium text-white" :style="{ backgroundColor: l.color }">{{ l.name }}</span>
            <span v-if="task.labels.length > 3" class="text-[10px] text-gray-400">+{{ task.labels.length - 3 }}</span>
        </div>

        <p v-if="task.epic" class="mt-2 truncate rounded bg-purple-50 px-1.5 py-0.5 text-[11px] text-purple-700" :title="task.epic.title">{{ task.epic.key }} · {{ task.epic.title }}</p>

        <div class="mt-2 flex items-center gap-2 text-xs text-gray-500">
            <InlineField v-if="due || can.update" :model-value="task.due_date" type="date" :can="can.update" :label="`Due date of ${task.key}`" :save="(v) => patchTask(task.key, { due_date: v })" :task-key="task.key" field="due">
                <template #display>
                    <span :class="['inline-flex items-center gap-1', task.overdue ? 'font-medium text-red-600' : '']" :title="task.overdue ? 'Overdue' : 'Due date'">
                        <CalendarDaysIcon class="h-3.5 w-3.5" /> {{ due ?? 'Set date' }}
                    </span>
                </template>
            </InlineField>
            <span v-if="showEffort && task.story_points !== null" class="rounded-full bg-gray-100 px-1.5 py-0.5 font-medium text-gray-600" title="Story points">{{ task.story_points }}</span>
            <span class="ml-auto">
                <InlineField :model-value="task.assignee?.id ?? null" type="select" :options="assigneeChoices" :can="can.assign" :label="`Assignee of ${task.key}`" :save="(v) => assignTask(task.key, v)" :task-key="task.key" field="assignee">
                    <template #display>
                        <Avatar v-if="task.assignee" :name="task.assignee.name" size="sm" />
                        <span v-else class="flex h-6 w-6 items-center justify-center rounded-full border border-dashed border-gray-300 text-[10px] text-gray-400" title="Unassigned">+</span>
                    </template>
                </InlineField>
            </span>
        </div>
    </article>
</template>
