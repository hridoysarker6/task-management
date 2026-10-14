<script setup>
import InputError from '@/Components/InputError.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Btn from '@/Components/UI/Btn.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    person: { type: Object, required: true },
    directReports: { type: Array, required: true },
    managers: { type: Array, required: true },
    projects: { type: Array, default: () => [] },
    projectManagers: { type: Array, default: () => [] },
    exitTypes: { type: Array, required: true },
    today: { type: String, required: true },
});

const form = useForm({
    exit_type: 'resigned',
    left_at: props.today,
    exit_reason: '',
    // Pre-select their own manager when that person can take over.
    new_manager_id: props.managers.find((m) => m.id === props.person.default_manager_id)?.id ?? null,
    new_project_manager_id: props.projectManagers.find((m) => m.id === props.person.default_manager_id)?.id ?? null,
});

const immediate = computed(() => form.left_at && form.left_at <= props.today);
const managerOptions = computed(() => [{ value: null, label: 'Choose a manager…' }, ...props.managers.map((m) => ({ value: m.id, label: `${m.name} (${m.role})` }))]);

const projectManagerOptions = computed(() => [{ value: null, label: 'Choose a project manager…' }, ...props.projectManagers.map((m) => ({ value: m.id, label: `${m.name} (${m.role})` }))]);

const submit = () => form.post(route('people.offboard.store', props.person.id));
const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <Head :title="`Offboard ${person.name}`" />

    <AppLayout>
        <PageHeader :title="`Offboard ${person.name}`" :subtitle="`${person.role}. Their account is kept; only their access to this company ends.`" />

        <form class="max-w-2xl space-y-6" @submit.prevent="submit">
            <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-gray-900">1. Exit details</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="exit_type">Exit type</label>
                        <SelectInput id="exit_type" v-model="form.exit_type" :options="exitTypes" class="mt-1" />
                        <InputError class="mt-1" :message="form.errors.exit_type" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="left_at">Last working day</label>
                        <input id="left_at" v-model="form.left_at" type="date" required :class="input" />
                        <InputError class="mt-1" :message="form.errors.left_at" />
                    </div>
                </div>
                <p class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600">
                    <template v-if="immediate">They leave <strong>immediately</strong> and are signed out.</template>
                    <template v-else>They stay active until the last working day, then the daily job removes their access.</template>
                </p>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="exit_reason">Reason / note <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea id="exit_reason" v-model="form.exit_reason" rows="3" :class="input" />
                    <InputError class="mt-1" :message="form.errors.exit_reason" />
                </div>
            </section>

            <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-gray-900">2. Handover</h2>

                <div v-if="directReports.length">
                    <p class="text-sm text-gray-600">These people report to {{ person.name }}. Choose who they will report to from now on.</p>
                    <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-100">
                        <li v-for="r in directReports" :key="r.id" class="flex items-center gap-2 px-3 py-2 text-sm">
                            <Avatar :name="r.name" size="sm" /> {{ r.name }} <span class="ml-auto text-xs text-gray-500">{{ r.role }}</span>
                        </li>
                    </ul>
                    <label class="mt-3 block text-sm font-medium text-gray-700" for="new_manager_id">New manager</label>
                    <SelectInput id="new_manager_id" v-model="form.new_manager_id" :options="managerOptions" class="mt-1" />
                    <p v-if="!managers.length" class="mt-1 text-sm text-red-600">Nobody outranks all of these people. Change someone's role or add a more senior person first.</p>
                    <InputError class="mt-1" :message="form.errors.new_manager_id" />
                </div>
                <p v-else-if="!projects.length" class="text-sm text-gray-500">Nobody reports to {{ person.name }}, so there is nothing to hand over.</p>

                <div v-if="projects.length">
                    <p class="text-sm text-gray-600">{{ person.name }} is the project manager of these projects. Choose who takes over.</p>
                    <ul class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-100">
                        <li v-for="p in projects" :key="p.id" class="flex items-center gap-2 px-3 py-2 text-sm">
                            <span class="rounded bg-indigo-50 px-1.5 font-mono text-xs font-semibold text-indigo-700">{{ p.key }}</span> {{ p.name }}
                        </li>
                    </ul>
                    <label class="mt-3 block text-sm font-medium text-gray-700" for="new_project_manager_id">New project manager</label>
                    <SelectInput id="new_project_manager_id" v-model="form.new_project_manager_id" :options="projectManagerOptions" class="mt-1" />
                    <InputError class="mt-1" :message="form.errors.new_project_manager_id" />
                </div>

                <p class="text-xs text-gray-400">Open tasks and timesheet approvals will appear here once those modules are available.</p>
            </section>

            <InputError :message="form.errors.member" />

            <div class="flex items-center justify-between">
                <Link :href="route('people.show', person.id)" class="text-sm text-gray-600 underline hover:text-gray-900">Cancel</Link>
                <Btn type="submit" variant="danger" :loading="form.processing">{{ immediate ? 'Offboard now' : 'Schedule exit' }}</Btn>
            </div>
        </form>
    </AppLayout>
</template>
