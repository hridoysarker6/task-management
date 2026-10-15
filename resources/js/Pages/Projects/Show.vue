<script setup>
import ProjectHeader from '@/Components/Projects/ProjectHeader.vue';
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import BurndownChart from '@/Components/Charts/BurndownChart.vue';
import VelocityChart from '@/Components/Charts/VelocityChart.vue';
import HealthBadge from '@/Components/Projects/HealthBadge.vue';
import Btn from '@/Components/UI/Btn.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, required: true },
    statuses: { type: Array, required: true },
    members: { type: Array, required: true },
    memberCount: { type: Number, default: 0 },
    labelCount: { type: Number, default: 0 },
    recentTasks: { type: Array, default: () => [] },
    taskCount: { type: Number, default: 0 },
    triageCount: { type: Number, default: null },
    canCreateTask: { type: Boolean, default: false },
    canSeeTrash: { type: Boolean, default: false },
    progress: { type: Object, default: null },
    health: { type: Object, default: null },
    burndown: { type: Object, default: null },
    velocity: { type: Object, default: null },
    activity: { type: Array, default: () => [] },
});

const when = (iso) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const describe = (a) => {
    switch (a.event) {
        case 'created': return 'created';
        case 'status_changed': return `moved from ${a.old ?? '—'} to ${a.new ?? '—'}`;
        case 'assigned': return a.new ? `assigned to ${a.new}` : 'unassigned';
        case 'commented': return 'commented on';
        case 'work_logged': return 'logged work on';
        case 'updated': return `changed ${a.field ?? 'a field'} of`;
        default: return a.event.replaceAll('_', ' ');
    }
};

const createTask = () => window.dispatchEvent(new CustomEvent('create-task', { detail: { projectId: props.project.id } }));

const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const roleLabel = { manager: 'Project manager', member: 'Member', stakeholder: 'Stakeholder', viewer: 'Viewer' };
const triageLabel = { off: 'Off', external_only: 'External stakeholders only', all_stakeholders: 'All stakeholders' };
</script>

<template>
    <Head :title="`${project.key} · ${project.name}`" />

    <AppLayout>
        <ProjectHeader :project="project" active="overview">
            <template #actions>
                <Btn v-if="triageCount !== null" variant="secondary" :href="route('projects.triage.index', project.id)">Triage<span v-if="triageCount" class="ml-1 rounded-full bg-purple-100 px-1.5 text-xs text-purple-700">{{ triageCount }}</span></Btn>
                <Btn v-if="canCreateTask" @click="createTask">Create task</Btn>
                <Btn v-if="project.abilities.update" variant="secondary" :href="route('projects.edit', project.id)">Edit</Btn>
            </template>
        </ProjectHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">About</h2>
                    <p v-if="project.description" class="whitespace-pre-line text-sm text-gray-700">{{ project.description }}</p>
                    <p v-else class="text-sm text-gray-400">No description.</p>

                    <dl class="mt-4 grid gap-x-6 gap-y-3 border-t border-gray-100 pt-4 sm:grid-cols-2">
                        <div><dt class="text-xs uppercase tracking-wide text-gray-400">Project manager</dt><dd class="mt-0.5 text-sm text-gray-800">{{ project.manager ?? '—' }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wide text-gray-400">Department</dt><dd class="mt-0.5 text-sm text-gray-800">{{ project.department ?? '—' }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wide text-gray-400">Start</dt><dd class="mt-0.5 text-sm text-gray-800">{{ fmt(project.start_date) }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wide text-gray-400">End</dt><dd class="mt-0.5 text-sm text-gray-800">{{ fmt(project.end_date) }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wide text-gray-400">Planning</dt><dd class="mt-0.5 text-sm text-gray-800">{{ project.uses_sprints ? 'Sprints (Scrum)' : 'Continuous board (Kanban)' }}</dd></div>
                        <div><dt class="text-xs uppercase tracking-wide text-gray-400">Triage</dt><dd class="mt-0.5 text-sm text-gray-800">{{ triageLabel[project.triage_mode] }}</dd></div>
                    </dl>
                </section>

                <section v-if="progress" class="rounded-xl border border-gray-200 bg-white p-5" aria-label="Progress">
                    <div class="mb-2 flex items-center gap-3">
                        <h2 class="text-sm font-semibold text-gray-900">Progress</h2>
                        <HealthBadge v-if="health" :health="health" />
                        <span class="ml-auto text-sm text-gray-500">{{ progress.percent }}% · {{ progress.done }} of {{ progress.total }} {{ progress.mode === 'points' ? 'points' : 'tasks' }}</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-valuenow="progress.percent" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-green-500" :style="{ width: progress.percent + '%' }" /></div>
                    <ul v-if="health?.reasons.length" class="mt-3 list-disc space-y-0.5 pl-5 text-sm text-gray-600"><li v-for="r in health.reasons" :key="r">{{ r }}</li></ul>

                    <div v-if="burndown || velocity?.sprints.length" class="mt-5 grid gap-5 border-t border-gray-100 pt-4 md:grid-cols-2">
                        <div v-if="burndown"><h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Burndown · {{ burndown.sprint.name }}</h3><BurndownChart :days="burndown.days" :committed="burndown.committed" :height="150" /></div>
                        <div v-if="velocity?.sprints.length"><h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Velocity</h3><VelocityChart :sprints="velocity.sprints" :average="velocity.average" /></div>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900">Recent tasks <span class="font-normal text-gray-400">({{ taskCount }})</span></h2>
                        <Link v-if="canSeeTrash" :href="route('projects.trash', project.id)" class="text-sm text-gray-500 hover:underline">Deleted tasks</Link>
                    </div>
                    <ul v-if="recentTasks.length" class="divide-y divide-gray-100">
                        <li v-for="t in recentTasks" :key="t.id" class="flex items-center gap-3 py-2 text-sm">
                            <TaskBits kind="type" :value="t.type" />
                            <Link :href="route('tasks.show', t.key)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ t.key }}</Link>
                            <Link :href="route('tasks.show', t.key)" :class="['min-w-0 flex-1 truncate hover:text-indigo-600', t.status?.category === 'done' && 'text-gray-400 line-through']">{{ t.title }}</Link>
                            <Avatar v-if="t.assignee" :name="t.assignee.name" size="sm" />
                            <TaskBits kind="status" :value="t.status" />
                        </li>
                    </ul>
                    <p v-else class="text-sm text-gray-400">No tasks yet.</p>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900">Workflow</h2>
                        <Link :href="route('projects.workflow.index', project.id)" class="text-sm font-medium text-indigo-600 hover:underline">Manage</Link>
                    </div>
                    <ol class="flex flex-wrap gap-2">
                        <li v-for="s in statuses" :key="s.id" class="flex items-center gap-1.5 rounded-full border border-gray-200 px-2.5 py-1 text-xs text-gray-700" :class="!s.show_on_board && 'opacity-60'">
                            <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: s.color }" /> {{ s.name }}
                        </li>
                    </ol>
                </section>
            </div>

            <aside class="space-y-6">
                <section v-if="activity.length" class="rounded-xl border border-gray-200 bg-white p-5" aria-label="Recent activity">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Recent activity</h2>
                    <ul class="space-y-2.5">
                        <li v-for="a in activity" :key="a.id" class="text-sm text-gray-700">
                            <span class="font-medium text-gray-900">{{ a.actor ?? 'The system' }}</span> {{ describe(a) }}
                            <Link :href="route('tasks.show', a.task)" class="font-mono text-xs font-semibold text-indigo-700 hover:underline">{{ a.task }}</Link>
                            <span class="block text-xs text-gray-400">{{ when(a.created_at) }}</span>
                        </li>
                    </ul>
                </section>
                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-900">Members ({{ memberCount }})</h2>
                        <Link :href="route('projects.members.index', project.id)" class="text-sm font-medium text-indigo-600 hover:underline">All</Link>
                    </div>
                    <ul class="space-y-2">
                        <li v-for="m in members" :key="m.id" class="flex items-center gap-2 text-sm">
                            <Avatar :name="m.name" size="sm" /> <span class="font-medium text-gray-800">{{ m.name }}</span>
                            <span class="ml-auto text-xs text-gray-500">{{ roleLabel[m.project_role] }}</span>
                        </li>
                        <li v-if="!members.length" class="text-sm text-gray-400">Nobody yet.</li>
                    </ul>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-600">
                    <div class="flex justify-between"><span>Labels</span><Link :href="route('projects.labels.index', project.id)" class="font-medium text-indigo-600 hover:underline">{{ labelCount }}</Link></div>
                    <p class="mt-3 text-xs text-gray-400">The board, backlog and progress arrive in the next releases.</p>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
