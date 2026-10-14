<script setup>
import EmploymentHistory from '@/Components/People/EmploymentHistory.vue';
import InputError from '@/Components/InputError.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    person: { type: Object, required: true },
    history: { type: Array, required: true },
    roles: { type: Array, required: true },
    departments: { type: Array, required: true },
    managers: { type: Array, required: true },
    canArchive: { type: Boolean, default: false },
    previousProjects: { type: Array, default: () => [] },
    today: { type: String, required: true },
});

const form = useForm({
    role_id: null,
    department_id: null,
    reports_to_id: null,
    designation: props.person.designation ?? '',
    employee_code: props.person.employee_code ?? '',
    joined_at: props.today,
    project_ids: [],
});

const role = computed(() => props.roles.find((r) => r.id === form.role_id));
const external = computed(() => role.value?.is_external ?? false);

const roleOptions = computed(() => [{ value: null, label: 'Select a role…' }, ...props.roles.map((r) => ({ value: r.id, label: r.name }))]);
const departmentOptions = computed(() => [{ value: null, label: 'No department' }, ...props.departments.map((d) => ({ value: d.id, label: d.name }))]);
const managerOptions = computed(() => [
    { value: null, label: 'No manager yet' },
    ...props.managers.filter((m) => !role.value || m.level < role.value.level).map((m) => ({ value: m.id, label: `${m.name} (${m.role})` })),
]);

const sameWho = ref('same'); // same | different
const submit = () => form.transform((d) => ({ ...d, reports_to_id: external.value ? null : d.reports_to_id })).post(route('people.rehire.store', props.person.id));

const confirmDifferent = ref(false);
const archive = () => router.post(route('people.rehire.different-person', props.person.id), {}, { onFinish: () => (confirmDifferent.value = false) });

const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <Head :title="`Rehire ${person.name}`" />

    <AppLayout>
        <PageHeader :title="`Rehire ${person.name}`" :subtitle="`${person.email} used to work here as ${person.last_role}.`" />

        <div class="grid gap-6 lg:grid-cols-5">
            <section class="rounded-xl border border-gray-200 bg-white p-5 lg:col-span-2">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Previous history</h2>
                <EmploymentHistory :periods="history" />
            </section>

            <div class="space-y-4 lg:col-span-3">
                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="text-sm font-semibold text-gray-900">Is this the same person?</h2>
                    <p class="mt-1 text-sm text-gray-500">Company emails are sometimes reused. Mixing two people's histories would corrupt your reports.</p>
                    <div class="mt-3 flex gap-3">
                        <label class="flex items-center gap-2 text-sm"><input v-model="sameWho" type="radio" value="same" class="text-indigo-600 focus:ring-indigo-500" /> Yes, the same person</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="sameWho" type="radio" value="different" class="text-indigo-600 focus:ring-indigo-500" /> No, someone else with this email</label>
                    </div>
                </section>

                <form v-if="sameWho === 'same'" class="space-y-4 rounded-xl border border-gray-200 bg-white p-5" @submit.prevent="submit">
                    <h2 class="text-sm font-semibold text-gray-900">New position</h2>
                    <p class="text-sm text-gray-500">Their old account and history come back. Old permissions and project memberships are <strong>not</strong> restored: they get the permissions of the new role only.</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="role">Role</label>
                            <SelectInput id="role" v-model="form.role_id" :options="roleOptions" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.role_id" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="department">Department</label>
                            <SelectInput id="department" v-model="form.department_id" :options="departmentOptions" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.department_id" />
                        </div>
                        <div v-if="!external" class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700" for="manager">Reports to</label>
                            <SelectInput id="manager" v-model="form.reports_to_id" :options="managerOptions" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.reports_to_id" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="designation">Designation</label>
                            <input id="designation" v-model="form.designation" type="text" :class="input" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="employee_code">Employee code</label>
                            <input id="employee_code" v-model="form.employee_code" type="text" :class="input" />
                            <InputError class="mt-1" :message="form.errors.employee_code" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="joined_at">Join date</label>
                            <input id="joined_at" v-model="form.joined_at" type="date" :class="input" />
                            <InputError class="mt-1" :message="form.errors.joined_at" />
                        </div>
                    </div>
                    <fieldset v-if="previousProjects.length">
                        <legend class="text-sm font-medium text-gray-700">Re-add to previous projects</legend>
                        <p class="text-xs text-gray-500">Project access is not restored automatically. Tick the projects they should rejoin.</p>
                        <div class="mt-2 space-y-1">
                            <label v-for="p in previousProjects" :key="p.id" class="flex items-center gap-2 text-sm text-gray-700">
                                <input v-model="form.project_ids" type="checkbox" :value="p.id" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                <span class="rounded bg-indigo-50 px-1.5 font-mono text-xs font-semibold text-indigo-700">{{ p.key }}</span> {{ p.name }}
                            </label>
                        </div>
                    </fieldset>
                    <InputError :message="form.errors.member" />

                    <div class="flex items-center justify-between pt-2">
                        <Link :href="route('people.index', { tab: 'former' })" class="text-sm text-gray-600 underline hover:text-gray-900">Cancel</Link>
                        <Btn type="submit" :loading="form.processing">Rehire</Btn>
                    </div>
                </form>

                <section v-else class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="text-sm font-semibold text-gray-900">A different person</h2>
                    <p class="mt-1 text-sm text-gray-600">The old account will be archived and its email renamed, so the history stays with the right person. Then you can invite the new person with this email.</p>
                    <p v-if="!canArchive" class="mt-3 rounded-lg bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
                        This account is still used in another company (or you cannot invite), so it cannot be archived here. Ask the platform administrator.
                    </p>
                    <div class="mt-4 flex justify-end"><Btn variant="danger" :disabled="!canArchive" @click="confirmDifferent = true">Archive old account and invite</Btn></div>
                </section>
            </div>
        </div>

        <ConfirmDialog
            :show="confirmDifferent"
            title="Archive the old account?"
            :message="`${person.email} will be renamed and can no longer sign in. Their history stays on record.`"
            confirm-text="Archive and continue"
            danger
            @confirm="archive"
            @cancel="confirmDifferent = false"
        />
    </AppLayout>
</template>
