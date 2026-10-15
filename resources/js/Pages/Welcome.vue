<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import Btn from '@/Components/UI/Btn.vue';
import { ArrowRightIcon, BuildingOffice2Icon, ChartBarIcon, ClockIcon, Squares2X2Icon, UserGroupIcon, ViewColumnsIcon } from '@heroicons/vue/24/outline';
import { Head, Link } from '@inertiajs/vue3';

// The public landing page. Signed-in people go straight to their dashboard from "Open Task Pro".
defineProps({
    canLogin: { type: Boolean, default: true },
    canRegister: { type: Boolean, default: true },
});

const features = [
    { icon: ViewColumnsIcon, title: 'Boards that move', text: 'Drag cards across a Kanban board, plan sprints in a backlog, or switch to a list with bulk actions.' },
    { icon: UserGroupIcon, title: 'Built around your hierarchy', text: 'From department heads to developers: everyone sees what their role and team allow, and nothing more.' },
    { icon: ClockIcon, title: 'Time you can trust', text: 'Log work on a task, fill a weekly timesheet and let team leads approve it in a click.' },
    { icon: ChartBarIcon, title: 'Progress at a glance', text: 'Dashboards, burndown, velocity and project health, with drill-down from the top to a single task.' },
    { icon: BuildingOffice2Icon, title: 'One home for many companies', text: 'Each company has its own people, projects and settings, kept completely separate.' },
    { icon: Squares2X2Icon, title: 'Requests without chaos', text: 'Stakeholders raise requests, your team triages them, and everyone is told what happens next.' },
];
</script>

<template>
    <Head title="Plan work. Ship together." />

    <div class="min-h-screen bg-white text-gray-800">
        <header class="sticky top-0 z-30 border-b border-gray-100 bg-white/80 backdrop-blur-md">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-5">
                <Link href="/" aria-label="Task Pro home"><BrandLogo /></Link>
                <nav v-if="canLogin" class="flex items-center gap-2">
                    <template v-if="$page.props.auth?.user">
                        <Btn :href="route('dashboard')" size="md">Open Task Pro <ArrowRightIcon class="h-4 w-4" /></Btn>
                    </template>
                    <template v-else>
                        <Btn :href="route('login')" variant="ghost">Log in</Btn>
                        <Btn v-if="canRegister" :href="route('register')">Get started</Btn>
                    </template>
                </nav>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative overflow-hidden">
            <div class="pointer-events-none absolute -top-40 left-1/2 h-[32rem] w-[56rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-violet-200/60 via-fuchsia-200/40 to-sky-200/60 blur-3xl" />
            <div class="relative mx-auto max-w-4xl px-5 pb-20 pt-20 text-center sm:pt-28">
                <p class="mx-auto mb-5 inline-flex items-center gap-2 rounded-full bg-violet-50 px-3.5 py-1 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-violet-500" /> Project management for growing teams
                </p>
                <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 sm:text-6xl">Plan the work.<br /><span class="text-gradient">Ship it together.</span></h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-gray-600">Task Pro brings projects, sprints, requests and timesheets into one calm workspace, with the right view for everyone from the CEO's office to the newest developer.</p>
                <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
                    <Btn v-if="canRegister" :href="route('register')" size="lg">Register your company <ArrowRightIcon class="h-4 w-4" /></Btn>
                    <Btn v-if="canLogin" :href="route('login')" variant="secondary" size="lg">Log in</Btn>
                </div>
            </div>

            <!-- A taste of the product -->
            <div class="relative mx-auto max-w-5xl px-5 pb-24">
                <div class="rounded-2xl border border-gray-200 bg-white p-3 shadow-xl ring-1 ring-gray-900/5">
                    <div class="grid gap-3 rounded-xl bg-gray-50 p-4 sm:grid-cols-3">
                        <div v-for="(col, i) in [{ t: 'To do', c: 'bg-gray-400', cards: ['Design the landing page', 'Set up CI'] }, { t: 'In progress', c: 'bg-amber-400', cards: ['Checkout flow', 'API docs', 'Fix login bug'] }, { t: 'Done', c: 'bg-emerald-500', cards: ['Onboarding emails'] }]" :key="i" class="rounded-xl bg-gray-100/70 p-3">
                            <p class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500"><span :class="['h-2 w-2 rounded-full', col.c]" />{{ col.t }}</p>
                            <div class="space-y-2">
                                <div v-for="card in col.cards" :key="card" class="rounded-lg border border-gray-200 bg-white p-3 text-left text-sm font-medium text-gray-800 shadow-sm">{{ card }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="border-t border-gray-100 bg-gray-50/60 py-20">
            <div class="mx-auto max-w-6xl px-5">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-gray-900">Everything a team needs, nothing it doesn't</h2>
                    <p class="mt-3 text-gray-600">Simple to start, deep enough to run a whole department.</p>
                </div>
                <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="f in features" :key="f.title" class="rounded-2xl border border-gray-200/80 bg-white p-6 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-50 text-violet-600 ring-1 ring-inset ring-violet-100"><component :is="f.icon" class="h-6 w-6" /></span>
                        <h3 class="mt-4 text-base font-semibold text-gray-900">{{ f.title }}</h3>
                        <p class="mt-1.5 text-sm leading-6 text-gray-600">{{ f.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Call to action -->
        <section class="mx-auto max-w-6xl px-5 py-20">
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-violet-600 to-indigo-800 px-8 py-14 text-center text-white shadow-xl">
                <div class="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-fuchsia-400/30 blur-3xl" />
                <h2 class="relative text-3xl font-bold tracking-tight">Ready to bring your team together?</h2>
                <p class="relative mx-auto mt-3 max-w-xl text-violet-100">Register your company, invite your people and have your first board running in minutes.</p>
                <div class="relative mt-8"><Link v-if="canRegister" :href="route('register')" class="inline-flex items-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-semibold text-violet-700 shadow-lg transition hover:bg-violet-50 active:scale-[0.98]">Get started <ArrowRightIcon class="h-4 w-4" /></Link></div>
            </div>
        </section>

        <footer class="border-t border-gray-100 py-8">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-5 text-sm text-gray-500">
                <BrandLogo size="sm" />
                <p>&copy; {{ new Date().getFullYear() }} Task Pro. All rights reserved.</p>
            </div>
        </footer>
    </div>
</template>
