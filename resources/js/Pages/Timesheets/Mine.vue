<script setup>
import InputError from '@/Components/InputError.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { formatDuration, parseDuration } from '@/lib/duration';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

const props = defineProps({
    week: { type: Object, required: true },
    prev: String,
    next: String,
    days: { type: Array, required: true },
    rows: { type: Array, required: true },
    total: { type: Number, default: 0 },
    sheet: { type: Object, required: true },
    editable: Boolean,
    suggestions: { type: Array, default: () => [] },
});

const statusColor = { draft: 'gray', submitted: 'blue', approved: 'green', returned: 'yellow' };
const statusLabel = { draft: 'Draft', submitted: 'Submitted, waiting for approval', approved: 'Approved', returned: 'Returned to you' };

// Rows the person added from the list but has not typed any time into yet.
const added = ref([]);
const allRows = computed(() => [
    ...props.rows,
    ...added.value.filter((a) => !props.rows.some((r) => r.task.id === a.id)).map((a) => ({ task: a, cells: {}, total: 0 })),
]);
const choices = computed(() => props.suggestions.filter((s) => !added.value.some((a) => a.id === s.id)));
const addRow = (event) => {
    const task = props.suggestions.find((s) => s.id === Number(event.target.value));
    if (task) added.value.push(task);
    event.target.value = '';
};

// "0m" would clutter an empty cell, so zero shows as nothing.
const dur = (m) => (m ? formatDuration(m) : '');

const error = ref('');
const saving = ref(false);

const save = async (row, day, event) => {
    const minutes = parseDuration(event.target.value);
    const current = row.cells[day.date]?.minutes ?? 0;
    if (Number.isNaN(minutes)) {
        error.value = 'Enter time as 1h 30m, 45m or 1.5h.';
        event.target.value = dur(current);
        return;
    }
    if ((minutes ?? 0) === current) {
        event.target.value = dur(current);
        return;
    }
    error.value = '';
    saving.value = true;
    try {
        await axios.post(route('timesheet.cell'), { task_id: row.task.id, date: day.date, minutes: minutes ?? 0 });
    } catch (e) {
        const errors = e.response?.data?.errors ?? {};
        error.value = e.response?.status === 403 ? 'You are not allowed to do that.' : (Object.values(errors)[0]?.[0] ?? 'That could not be saved.');
    } finally {
        saving.value = false;
        router.reload({ preserveScroll: true });
    }
};

const confirming = ref(false);
const submitting = ref(false);
const submit = () => {
    submitting.value = true;
    router.post(route('timesheet.submit'), { week: props.week.start }, { preserveScroll: true, onFinish: () => ((submitting.value = false), (confirming.value = false)) });
};

const fmtDay = (d) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const range = computed(() => `${fmtDay(props.week.start)} – ${fmtDay(props.week.end)}`);
const cellValue = (row, day) => dur(row.cells[day.date]?.minutes ?? 0);
const multi = (row, day) => (row.cells[day.date]?.entries ?? 0) > 1;
</script>

<template>
    <Head title="My timesheet" />

    <AppLayout>
        <PageHeader title="My timesheet" :subtitle="range" />

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-1">
                <Link :href="route('timesheet', { week: prev })" class="rounded-lg border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50" aria-label="Previous week"><ChevronLeftIcon class="h-4 w-4" /></Link>
                <Link v-if="!week.is_current" :href="route('timesheet')" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">This week</Link>
                <Link :href="route('timesheet', { week: next })" class="rounded-lg border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50" aria-label="Next week"><ChevronRightIcon class="h-4 w-4" /></Link>
            </div>
            <Badge :color="statusColor[sheet.status]">{{ statusLabel[sheet.status] }}</Badge>
            <span class="text-sm text-gray-500">Total <strong class="text-gray-900">{{ formatDuration(total) || '0m' }}</strong></span>
            <Btn v-if="editable" class="ml-auto" :disabled="total === 0" @click="confirming = true">Submit for approval</Btn>
        </div>

        <p v-if="sheet.status === 'returned' && sheet.review_note" role="status" class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
            <strong>{{ sheet.reviewer ?? 'Your approver' }} sent this back:</strong> {{ sheet.review_note }}
        </p>
        <p v-if="!editable" class="mb-4 text-sm text-gray-500">This week is locked{{ sheet.status === 'submitted' ? ' while it waits for approval' : '' }}. Entries can be changed again if it is returned.</p>
        <InputError class="mb-3" :message="error" />

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Task</th>
                        <th v-for="d in days" :key="d.date" class="w-24 px-2 py-2 text-center" :class="d.is_today ? 'bg-indigo-50 text-indigo-700' : ''">{{ d.label }}</th>
                        <th class="w-20 px-3 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-if="!allRows.length">
                        <td :colspan="days.length + 2" class="px-3 py-8 text-center text-gray-400">Nothing logged this week. Add a task below, or log work from a task.</td>
                    </tr>
                    <tr v-for="row in allRows" :key="row.task.id">
                        <td class="max-w-xs px-3 py-2">
                            <Link :href="route('tasks.show', row.task.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ row.task.key }}</Link>
                            <span class="ml-2 text-gray-800">{{ row.task.title }}</span>
                        </td>
                        <td v-for="d in days" :key="d.date" class="px-1 py-1 text-center" :class="d.is_today ? 'bg-indigo-50/40' : ''">
                            <input
                                v-if="editable && !d.is_future && !multi(row, d)"
                                type="text"
                                :value="cellValue(row, d)"
                                :aria-label="`${row.task.key} on ${d.label}`"
                                placeholder="–"
                                :disabled="saving"
                                class="w-full rounded border-transparent bg-transparent px-1 py-1 text-center text-sm hover:border-gray-300 focus:border-indigo-500 focus:bg-white focus:ring-indigo-500"
                                @change="save(row, d, $event)"
                            />
                            <span v-else :class="['block px-1 py-1', multi(row, d) ? 'cursor-help underline decoration-dotted' : 'text-gray-600']" :title="multi(row, d) ? 'Several entries: change them on the task' : ''">{{ cellValue(row, d) || '–' }}</span>
                        </td>
                        <td class="px-3 py-2 text-right font-medium text-gray-900">{{ dur(row.total) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-gray-50 text-sm font-medium text-gray-700">
                    <tr>
                        <td class="px-3 py-2">
                            <select v-if="editable && choices.length" aria-label="Add a task to the week" class="rounded-lg border-gray-300 py-1 text-sm focus:border-indigo-500 focus:ring-indigo-500" @change="addRow">
                                <option value="">+ Add a task…</option>
                                <option v-for="s in choices" :key="s.id" :value="s.id">{{ s.key }} · {{ s.title }}</option>
                            </select>
                            <span v-else>Day total</span>
                        </td>
                        <td v-for="d in days" :key="d.date" class="px-2 py-2 text-center" :class="d.total > 600 ? 'text-orange-600' : ''">{{ dur(d.total) }}</td>
                        <td class="px-3 py-2 text-right text-gray-900">{{ formatDuration(total) || '0m' }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <ConfirmDialog
            :show="confirming"
            title="Submit this week?"
            :message="`${formatDuration(total)} for ${range} will go to your approver and can't be changed until it is returned.`"
            confirm-text="Submit"
            :loading="submitting"
            @confirm="submit"
            @cancel="confirming = false"
        />
    </AppLayout>
</template>
