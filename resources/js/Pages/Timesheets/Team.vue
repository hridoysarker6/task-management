<script setup>
import InputError from '@/Components/InputError.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { formatDuration } from '@/lib/duration';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    week: { type: Object, required: true },
    prev: String,
    next: String,
    people: { type: Array, required: true },
    pending: { type: Array, default: () => [] },
    canApprove: Boolean,
    counts: { type: Object, default: () => ({}) },
});

const statusColor = { missing: 'red', draft: 'gray', submitted: 'blue', approved: 'green', returned: 'yellow' };
const statusLabel = { missing: 'Nothing logged', draft: 'Not submitted', submitted: 'Submitted', approved: 'Approved', returned: 'Returned' };

const fmt = (d) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const fmtDay = (d) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
const range = computed(() => `${fmt(props.week.start)} – ${fmt(props.week.end)}`);

const open = ref(null); // the pending timesheet whose entries are shown
const returning = ref(null);
const form = useForm({ note: '' });

const approve = (s) => router.post(route('timesheets.approve', s.id), {}, { preserveScroll: true });
const startReturn = (s) => {
    returning.value = s.id;
    form.reset();
    form.clearErrors();
};
const sendBack = (s) => form.post(route('timesheets.return', s.id), { preserveScroll: true, onSuccess: () => (returning.value = null) });
</script>

<template>
    <Head title="Team time" />

    <AppLayout>
        <PageHeader title="Team time" :subtitle="range" />

        <section v-if="canApprove" class="mb-8" aria-label="Waiting for approval">
            <h2 class="mb-2 text-sm font-semibold text-gray-900">Waiting for your approval <span class="font-normal text-gray-400">{{ pending.length }}</span></h2>
            <EmptyState v-if="!pending.length" title="Nothing to approve" description="Submitted timesheets from your team show up here." />
            <ul v-else class="space-y-3">
                <li v-for="s in pending" :key="s.id" class="rounded-xl border border-gray-200 bg-white">
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <Avatar :name="s.owner" />
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900">{{ s.owner }}</p>
                            <p class="text-xs text-gray-500">Week of {{ fmt(s.week_start) }} · {{ formatDuration(s.total_min) }}</p>
                        </div>
                        <button class="text-sm text-indigo-600 hover:underline" :aria-expanded="open === s.id" @click="open = open === s.id ? null : s.id">{{ open === s.id ? 'Hide entries' : 'View entries' }}</button>
                        <Btn size="sm" variant="secondary" @click="startReturn(s)">Return</Btn>
                        <Btn size="sm" @click="approve(s)">Approve</Btn>
                    </div>

                    <form v-if="returning === s.id" class="space-y-2 border-t border-gray-100 px-4 py-3" @submit.prevent="sendBack(s)">
                        <label class="block text-sm font-medium text-gray-700" :for="`note-${s.id}`">What needs to change?</label>
                        <textarea :id="`note-${s.id}`" v-model="form.note" rows="2" required maxlength="1000" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <InputError :message="form.errors.note || form.errors.timesheet" />
                        <div class="flex gap-2">
                            <Btn type="submit" size="sm" :loading="form.processing">Send back</Btn>
                            <Btn size="sm" variant="ghost" @click="returning = null">Cancel</Btn>
                        </div>
                    </form>

                    <table v-if="open === s.id" class="min-w-full divide-y divide-gray-100 border-t border-gray-100 text-sm">
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="e in s.entries" :key="e.id">
                                <td class="whitespace-nowrap px-4 py-2 text-gray-500">{{ fmtDay(e.date) }}</td>
                                <td class="px-2 py-2"><span class="font-mono text-xs font-semibold text-indigo-700">{{ e.task.key }}</span> {{ e.task.title }}<span v-if="e.note" class="block text-xs text-gray-500">{{ e.note }}</span></td>
                                <td class="whitespace-nowrap px-4 py-2 text-right font-medium">{{ formatDuration(e.minutes) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </li>
            </ul>
        </section>

        <section aria-label="Week overview">
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <h2 class="text-sm font-semibold text-gray-900">Who has submitted</h2>
                <div class="flex items-center gap-1">
                    <Link :href="route('timesheets.team', { week: prev })" class="rounded-lg border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50" aria-label="Previous week"><ChevronLeftIcon class="h-4 w-4" /></Link>
                    <Link v-if="!week.is_current" :href="route('timesheets.team')" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">This week</Link>
                    <Link :href="route('timesheets.team', { week: next })" class="rounded-lg border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-gray-50" aria-label="Next week"><ChevronRightIcon class="h-4 w-4" /></Link>
                </div>
                <span class="text-xs text-gray-500">
                    <template v-for="(label, key) in statusLabel" :key="key"><span v-if="counts[key]" class="mr-3">{{ counts[key] }} {{ label.toLowerCase() }}</span></template>
                </span>
            </div>

            <EmptyState v-if="!people.length" title="No one to show" description="People in your reporting line who log time appear here." />

            <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-4 py-2">Person</th><th class="px-3 py-2">Status</th><th class="px-3 py-2 text-right">Logged</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="p in people" :key="p.id">
                            <td class="px-4 py-2"><span class="flex items-center gap-2"><Avatar :name="p.name" size="sm" /> {{ p.name }} <span class="text-xs text-gray-400">{{ p.role }}</span></span></td>
                            <td class="px-3 py-2">
                                <Badge :color="statusColor[p.status]">{{ statusLabel[p.status] }}</Badge>
                                <span v-if="p.status === 'returned' && p.note" class="ml-2 text-xs text-gray-500">{{ p.note }}</span>
                            </td>
                            <td class="px-3 py-2 text-right font-medium">{{ formatDuration(p.total_min) || '–' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
