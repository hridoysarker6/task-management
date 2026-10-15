<script setup>
import BarList from '@/Components/Charts/BarList.vue';
import DonutChart from '@/Components/Charts/DonutChart.vue';
import VelocityChart from '@/Components/Charts/VelocityChart.vue';
import HealthBadge from '@/Components/Projects/HealthBadge.vue';
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import { formatDuration } from '@/lib/duration';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    onboarding: { type: String, default: null }, // in_progress | done | null (not an admin)
    dashboard: { type: Object, default: null },
});

const page = usePage();
const d = computed(() => props.dashboard);

const subtitles = {
    worker: 'Your work this week.',
    lead: 'Your work and your team.',
    manager: 'Your work, your teams and their projects.',
    executive: 'Your area at a glance.',
    company: 'The whole company at a glance.',
    stakeholder: 'Your requests and the projects you follow.',
};

const statusColors = { todo: '#9ca3af', in_progress: '#f59e0b', done: '#22c55e' };
const donut = computed(() => (d.value?.status ?? []).map((s) => ({ label: s.label, count: s.count, color: statusColors[s.category] })));
const fmt = (x) => (x ? new Date(x + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '');
const card = 'rounded-xl border border-gray-200 bg-white p-5';
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <PageHeader :title="`Welcome, ${page.props.auth.user.name}`" :subtitle="d ? subtitles[d.layout] : ''" />

        <div v-if="onboarding === 'in_progress'" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
            <p class="text-sm text-indigo-900"><strong>Finish setting up your company</strong>: departments, roles, people and hierarchy.</p>
            <Link :href="route('onboarding.show')" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Continue setup</Link>
        </div>

        <EmptyState v-if="!page.props.currentOrganization" title="You are not part of a company yet" description="Ask your Company Admin to invite you, or register a new company." />

        <div v-else-if="d" class="space-y-6">
            <!-- company numbers (admins) -->
            <section v-if="d.company" class="grid grid-cols-2 gap-4 lg:grid-cols-4" aria-label="Company">
                <div :class="card"><p class="text-xs uppercase tracking-wide text-gray-400">People</p><p class="mt-1 text-2xl font-semibold">{{ d.company.people }}</p></div>
                <div :class="card"><p class="text-xs uppercase tracking-wide text-gray-400">Active projects</p><p class="mt-1 text-2xl font-semibold">{{ d.company.projects }}</p></div>
                <div :class="card"><p class="text-xs uppercase tracking-wide text-gray-400">Open tasks</p><p class="mt-1 text-2xl font-semibold">{{ d.company.open_tasks }}</p></div>
                <div :class="card">
                    <p class="text-xs uppercase tracking-wide text-gray-400">Project health</p>
                    <p class="mt-1 text-sm"><span class="font-semibold text-green-600">{{ d.company.health.on_track }}</span> on track · <span class="font-semibold text-yellow-600">{{ d.company.health.at_risk }}</span> at risk · <span class="font-semibold text-red-600">{{ d.company.health.delayed }}</span> delayed</p>
                </div>
            </section>

            <!-- my work -->
            <section class="grid gap-4 lg:grid-cols-3" aria-label="My work">
                <div class="grid grid-cols-2 gap-4 lg:col-span-1 lg:grid-cols-1">
                    <Link :href="route('my-work')" :class="[card, 'hover:border-indigo-300']"><p class="text-xs uppercase tracking-wide text-gray-400">My open tasks</p><p class="mt-1 text-2xl font-semibold">{{ d.my.open }}</p></Link>
                    <div :class="card"><p class="text-xs uppercase tracking-wide text-gray-400">Due in 7 days</p><p class="mt-1 text-2xl font-semibold">{{ d.my.due_soon }}</p></div>
                    <div :class="card"><p class="text-xs uppercase tracking-wide text-gray-400">Overdue</p><p :class="['mt-1 text-2xl font-semibold', d.my.overdue ? 'text-red-600' : '']">{{ d.my.overdue }}</p></div>
                    <Link v-if="$page.props.auth.permissions?.includes('worklog.create')" :href="route('timesheet')" :class="[card, 'hover:border-indigo-300']"><p class="text-xs uppercase tracking-wide text-gray-400">Logged this week</p><p class="mt-1 text-2xl font-semibold">{{ formatDuration(d.my.week_minutes) || '0m' }}</p></Link>
                </div>

                <div :class="[card, 'lg:col-span-2']">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Next up</h2>
                    <p v-if="!d.my.tasks.length" class="text-sm text-gray-400">Nothing assigned to you.</p>
                    <ul v-else class="divide-y divide-gray-100">
                        <li v-for="t in d.my.tasks" :key="t.id" class="flex items-center gap-2 py-2 text-sm">
                            <TaskBits kind="type" :value="t.type" />
                            <Link :href="route('tasks.show', t.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ t.key }}</Link>
                            <Link :href="route('tasks.show', t.key)" class="min-w-0 flex-1 truncate text-gray-900 hover:text-indigo-600">{{ t.title }}</Link>
                            <TaskBits kind="status" :value="t.status" />
                            <span v-if="t.due_date" :class="['text-xs', t.overdue ? 'font-medium text-red-600' : 'text-gray-500']">{{ fmt(t.due_date) }}</span>
                        </li>
                    </ul>
                    <div v-if="d.my.sprints.length" class="mt-4 space-y-2 border-t border-gray-100 pt-3">
                        <div v-for="s in d.my.sprints" :key="s.id" class="text-sm">
                            <div class="flex justify-between"><Link :href="route('projects.board', s.project.id)" class="text-gray-800 hover:text-indigo-600">{{ s.project.key }} · {{ s.name }}</Link><span class="text-gray-500">{{ s.percent }}% · {{ s.days_left < 0 ? 'overdue' : s.days_left + ' d left' }}</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-indigo-500" :style="{ width: s.percent + '%' }" /></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- a lead's team -->
            <section v-if="d.team" class="grid gap-4 lg:grid-cols-3" aria-label="Team">
                <div :class="[card, 'lg:col-span-2']">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900">Team workload</h2>
                        <Link v-if="d.team.pending_timesheets" :href="route('timesheets.team')" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">{{ d.team.pending_timesheets }} timesheet{{ d.team.pending_timesheets === 1 ? '' : 's' }} to approve</Link>
                    </div>
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-gray-400"><tr><th class="py-1">Person</th><th class="py-1 text-right">To do</th><th class="py-1 text-right">In progress</th><th class="py-1 text-right">Done</th><th class="py-1 text-right">Overdue</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="p in d.team.people" :key="p.id">
                                <td class="py-1.5"><Link :href="route('drill', p.id)" class="flex items-center gap-2 hover:text-indigo-600"><Avatar :name="p.name" size="sm" /> {{ p.name }}</Link></td>
                                <td class="py-1.5 text-right">{{ p.todo }}</td><td class="py-1.5 text-right">{{ p.in_progress }}</td><td class="py-1.5 text-right text-gray-500">{{ p.done }}</td>
                                <td :class="['py-1.5 text-right', p.overdue ? 'font-medium text-red-600' : 'text-gray-400']">{{ p.overdue }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div :class="card">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Needs attention</h2>
                    <p v-if="!d.team.attention.length" class="text-sm text-gray-400">Nothing overdue or blocked.</p>
                    <ul v-else class="space-y-2">
                        <li v-for="t in d.team.attention" :key="t.id" class="text-sm">
                            <Link :href="route('tasks.show', t.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ t.key }}</Link>
                            <span class="ml-1 text-gray-800">{{ t.title }}</span>
                            <span class="block text-xs text-gray-500">{{ t.assignee?.name ?? 'Unassigned' }} · {{ t.status.name }}<span v-if="t.overdue" class="text-red-600"> · due {{ fmt(t.due_date) }}</span></span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- roll-up of each direct report -->
            <section v-if="d.rollup?.length" :class="card" aria-label="Teams">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Your teams</h2>
                <ul class="divide-y divide-gray-100">
                    <li v-for="r in d.rollup" :key="r.id" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                        <Link :href="route('drill', r.id)" class="flex min-w-[10rem] flex-1 items-center gap-2 hover:text-indigo-600"><Avatar :name="r.name" size="sm" /><span><span class="font-medium text-gray-900">{{ r.name }}</span> <span class="text-xs text-gray-400">{{ r.role }}<template v-if="r.people"> · {{ r.people }} below</template></span></span></Link>
                        <div class="w-40"><div class="h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-green-500" :style="{ width: r.percent + '%' }" /></div></div>
                        <span class="w-12 text-right text-gray-600">{{ r.percent }}%</span>
                        <Link :href="route('drill', { membership: r.id, show: 'open' })" class="w-16 text-right text-gray-600 hover:text-indigo-600">{{ r.open }} open</Link>
                        <Link :href="route('drill', { membership: r.id, show: 'overdue' })" :class="['w-20 text-right', r.overdue ? 'font-medium text-red-600' : 'text-gray-400']">{{ r.overdue }} overdue</Link>
                    </li>
                </ul>
            </section>

            <!-- projects -->
            <section v-if="d.projects" class="grid gap-4 lg:grid-cols-3" aria-label="Projects">
                <div :class="[card, 'lg:col-span-2']">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Projects</h2>
                    <p v-if="!d.projects.length" class="text-sm text-gray-400">No active projects.</p>
                    <ul v-else class="divide-y divide-gray-100">
                        <li v-for="p in d.projects" :key="p.id" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                            <Link :href="route('projects.show', p.id)" class="min-w-[10rem] flex-1 hover:text-indigo-600"><span class="font-mono text-xs font-semibold text-indigo-700">{{ p.key }}</span> {{ p.name }}<span v-if="p.manager" class="block text-xs text-gray-400">{{ p.manager }}</span></Link>
                            <div class="w-32"><div class="h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-indigo-500" :style="{ width: p.health.progress + '%' }" /></div></div>
                            <span class="w-10 text-right text-gray-600">{{ p.health.progress }}%</span>
                            <HealthBadge :health="p.health" />
                        </li>
                    </ul>
                </div>
                <div :class="card">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Work by status</h2>
                    <DonutChart :items="donut" />
                    <Link v-if="d.triage?.count" :href="route('projects.index')" class="mt-4 inline-block rounded-full bg-purple-100 px-2.5 py-1 text-xs font-medium text-purple-700">{{ d.triage.count }} request{{ d.triage.count === 1 ? '' : 's' }} in triage</Link>
                </div>
            </section>

            <section v-if="d.velocity?.length" class="grid gap-4 lg:grid-cols-3" aria-label="Velocity">
                <div v-for="v in d.velocity" :key="v.id" :class="card">
                    <h2 class="mb-2 text-sm font-semibold text-gray-900">Velocity · {{ v.key }}</h2>
                    <VelocityChart :sprints="v.sprints" :average="v.average" />
                </div>
            </section>

            <!-- a client's requests -->
            <section v-if="d.requests" class="grid gap-4 lg:grid-cols-2" aria-label="Requests">
                <div :class="card">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Your requests</h2>
                    <p v-if="!d.requests.by_status.length" class="text-sm text-gray-400">You have not raised any request yet.</p>
                    <BarList v-else :items="d.requests.by_status.map((s) => ({ label: s.name, value: s.count }))" />
                </div>
                <div :class="card">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Projects you follow</h2>
                    <ul class="space-y-3">
                        <li v-for="p in d.requests.projects" :key="p.id" class="text-sm">
                            <div class="flex justify-between"><Link :href="route('projects.show', p.id)" class="text-gray-800 hover:text-indigo-600">{{ p.key }} · {{ p.name }}</Link><span class="text-gray-500">{{ p.percent }}%</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-green-500" :style="{ width: p.percent + '%' }" /></div>
                        </li>
                    </ul>
                </div>
            </section>
        </div>

        <EmptyState v-else title="Nothing to show yet" description="Projects, tasks and progress show up here once you are on a project." />
    </AppLayout>
</template>
