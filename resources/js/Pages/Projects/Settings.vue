<script setup>
import InputError from '@/Components/InputError.vue';
import ProjectHeader from '@/Components/Projects/ProjectHeader.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ project: { type: Object, required: true } });

const p = props.project;
const canEdit = p.abilities.manage_settings;

const form = useForm({
    uses_sprints: p.uses_sprints,
    triage_mode: p.triage_mode,
    client_can_see_all: p.client_can_see_all,
    allow_self_close: p.allow_self_close,
    progress_mode: p.progress_mode,
});
const save = () => form.put(route('projects.settings.update', p.id), { preserveScroll: true });

const triageOptions = [
    { value: 'off', label: 'Off: requests go straight to the backlog' },
    { value: 'external_only', label: 'External stakeholders only' },
    { value: 'all_stakeholders', label: 'All stakeholders (internal and external)' },
];
const progressOptions = [
    { value: 'count', label: 'Number of tasks done' },
    { value: 'points', label: 'Story points done' },
];

// --- Project status ------------------------------------------------------------------------
const actions = {
    on_hold: { label: 'Put on hold', text: 'Work pauses. You can resume it any time.', variant: 'secondary' },
    active: { label: p.status === 'archived' ? 'Restore project' : p.status === 'completed' ? 'Reopen project' : 'Resume project', text: 'The project becomes active again.', variant: 'primary' },
    completed: { label: 'Mark as completed', text: 'The work is finished. It stays visible and can be reopened.', variant: 'secondary' },
    archived: { label: 'Archive project', text: 'The project becomes read-only and is hidden from the default list.', variant: 'danger' },
};
const confirming = ref(null);
const busy = ref(false);
const change = () =>
    router.post(route('projects.status', p.id), { status: confirming.value }, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => ((busy.value = false), (confirming.value = null)) });

const deleting = ref(false);
const destroy = () => router.delete(route('projects.destroy', p.id));
</script>

<template>
    <Head :title="`${project.key} · Settings`" />

    <AppLayout>
        <ProjectHeader :project="project" active="settings" />

        <div class="max-w-2xl space-y-6">
            <form class="space-y-5 rounded-xl border border-gray-200 bg-white p-5" @submit.prevent="save">
                <h2 class="text-sm font-semibold text-gray-900">Working style</h2>

                <label class="flex items-start gap-3 text-sm">
                    <input v-model="form.uses_sprints" type="checkbox" :disabled="!canEdit" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    <span><span class="font-medium text-gray-900">Use sprints (Scrum)</span><br /><span class="text-gray-500">Turn off for a continuous Kanban board with no sprints.</span></span>
                </label>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="triage">Triage for requests</label>
                    <SelectInput id="triage" v-model="form.triage_mode" :options="triageOptions" :disabled="!canEdit" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-500">Requests raised by these people wait in Triage until someone accepts them.</p>
                    <InputError class="mt-1" :message="form.errors.triage_mode" />
                </div>

                <label class="flex items-start gap-3 text-sm">
                    <input v-model="form.client_can_see_all" type="checkbox" :disabled="!canEdit" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    <span><span class="font-medium text-gray-900">Clients can see all tasks</span><br /><span class="text-gray-500">By default external stakeholders only see tasks they raised or follow.</span></span>
                </label>

                <label class="flex items-start gap-3 text-sm">
                    <input v-model="form.allow_self_close" type="checkbox" :disabled="!canEdit" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    <span><span class="font-medium text-gray-900">Developers can close their own tasks</span><br /><span class="text-gray-500">Otherwise a lead or manager reviews and closes them.</span></span>
                </label>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="progress">Progress is measured by</label>
                    <SelectInput id="progress" v-model="form.progress_mode" :options="progressOptions" :disabled="!canEdit" class="mt-1" />
                    <InputError class="mt-1" :message="form.errors.progress_mode" />
                </div>

                <InputError :message="form.errors.project" />
                <div v-if="canEdit" class="flex justify-end"><Btn type="submit" :loading="form.processing">Save settings</Btn></div>
                <p v-else class="text-xs text-gray-400">You can view these settings but not change them.</p>
            </form>

            <section v-if="project.abilities.change_status" class="space-y-3 rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-gray-900">Project status</h2>
                <ul class="divide-y divide-gray-100">
                    <li v-for="s in project.moves" :key="s" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <p class="max-w-md text-sm text-gray-600">{{ actions[s].text }}</p>
                        <Btn :variant="actions[s].variant" size="sm" @click="confirming = s">{{ actions[s].label }}</Btn>
                    </li>
                </ul>
            </section>

            <section v-if="project.abilities.delete" class="rounded-xl border border-red-200 bg-red-50/50 p-5">
                <h2 class="text-sm font-semibold text-red-800">Delete project</h2>
                <p class="mt-1 text-sm text-red-700">The project disappears from every list. An administrator can restore it from the database; nothing is erased.</p>
                <Btn class="mt-3" variant="danger" size="sm" @click="deleting = true">Delete project</Btn>
            </section>
        </div>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming ? actions[confirming].label + '?' : ''"
            :message="confirming ? actions[confirming].text : ''"
            :confirm-text="confirming ? actions[confirming].label : ''"
            :danger="confirming === 'archived'"
            :loading="busy"
            @confirm="change"
            @cancel="confirming = null"
        />
        <ConfirmDialog :show="deleting" title="Delete this project?" :message="`${project.key} · ${project.name} will be removed from every list.`" confirm-text="Delete" danger @confirm="destroy" @cancel="deleting = false" />
    </AppLayout>
</template>
