<script setup>
import EmploymentHistory from '@/Components/People/EmploymentHistory.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    person: { type: Object, required: true },
    reports: { type: Array, required: true },
    history: { type: Array, required: true },
    abilities: { type: Object, required: true },
    options: { type: Object, default: null },
});

const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

const fields = computed(() => [
    ['Role', props.person.role],
    ['Department', props.person.department],
    ['Reports to', props.person.manager],
    ['Designation', props.person.designation],
    ['Employee code', props.person.employee_code],
    ['Type', props.person.user_type === 'external' ? 'External' : 'Internal'],
    ['Joined', fmt(props.person.joined_at)],
    ['Phone', props.person.phone],
]);

// --- Edit ----------------------------------------------------------------------------------
const editing = ref(false);
const form = useForm({
    role_id: props.person.role_id,
    department_id: props.person.department_id,
    reports_to_id: props.person.reports_to_id,
    designation: props.person.designation ?? '',
    employee_code: props.person.employee_code ?? '',
});

const open = () => {
    form.defaults({
        role_id: props.person.role_id,
        department_id: props.person.department_id,
        reports_to_id: props.person.reports_to_id,
        designation: props.person.designation ?? '',
        employee_code: props.person.employee_code ?? '',
    }).reset();
    form.clearErrors();
    editing.value = true;
};

// Send only what this person is allowed to change.
const submit = () =>
    form
        .transform((d) => {
            const out = { department_id: d.department_id, designation: d.designation || null, employee_code: d.employee_code || null };
            if (props.abilities.change_role) out.role_id = d.role_id;
            if (props.abilities.change_manager) out.reports_to_id = d.reports_to_id;
            if (!props.abilities.edit) {
                delete out.department_id;
                delete out.designation;
                delete out.employee_code;
            }
            return out;
        })
        .put(route('people.update', props.person.id), { preserveScroll: true, onSuccess: () => (editing.value = false) });

const roleOptions = computed(() => (props.options?.roles ?? []).map((r) => ({ value: r.id, label: r.name })));
const departmentOptions = computed(() => [{ value: null, label: 'No department' }, ...(props.options?.departments ?? []).map((d) => ({ value: d.id, label: d.name }))]);
const managerOptions = computed(() => [{ value: null, label: 'No manager' }, ...(props.options?.managers ?? []).map((m) => ({ value: m.id, label: `${m.name} (${m.role})` }))]);

const cancelling = ref(false);
const cancelExit = () => router.delete(route('people.offboard.cancel', props.person.id), { preserveScroll: true, onFinish: () => (cancelling.value = false) });

const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <Head :title="person.name" />

    <AppLayout>
        <Link :href="route('people.index', { tab: person.is_former ? 'former' : 'active' })" class="mb-3 inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900">
            <ArrowLeftIcon class="h-4 w-4" /> People
        </Link>

        <PageHeader :title="person.name" :subtitle="person.email">
            <template #actions>
                <Badge v-if="person.is_former" color="gray">Former employee</Badge>
                <Badge v-else-if="person.scheduled_exit_at" color="yellow">Leaves {{ fmt(person.scheduled_exit_at) }}</Badge>
                <Btn v-if="abilities.edit || abilities.change_role || abilities.change_manager" variant="secondary" @click="open">Edit</Btn>
                <Btn v-if="abilities.offboard && person.scheduled_exit_at" variant="secondary" @click="cancelling = true">Cancel exit</Btn>
                <Btn v-if="abilities.offboard && !person.scheduled_exit_at" variant="danger" :href="route('people.offboard.create', person.id)">Offboard</Btn>
                <Btn v-if="abilities.rehire" :href="route('people.rehire.create', person.id)">Rehire</Btn>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <div class="mb-4 flex items-center gap-3">
                        <Avatar :name="person.name" size="lg" :former="person.is_former" />
                        <div>
                            <div class="font-medium text-gray-900">{{ person.name }}</div>
                            <div class="text-sm text-gray-500">{{ person.role }}<template v-if="person.designation"> &middot; {{ person.designation }}</template></div>
                        </div>
                    </div>
                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        <div v-for="[label, value] in fields" :key="label">
                            <dt class="text-xs uppercase tracking-wide text-gray-400">{{ label }}</dt>
                            <dd class="mt-0.5 text-sm text-gray-800">{{ value || '—' }}</dd>
                        </div>
                    </dl>
                    <div v-if="person.is_former" class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-700">
                        Left on <strong>{{ fmt(person.left_at) }}</strong><template v-if="person.exit_type"> ({{ person.exit_type.replace('_', ' ') }})</template>.
                        <p v-if="person.exit_reason" class="mt-1 text-gray-500">{{ person.exit_reason }}</p>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Employment history</h2>
                    <EmploymentHistory :periods="history" />
                </section>
            </div>

            <aside>
                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Direct reports</h2>
                    <p v-if="!reports.length" class="text-sm text-gray-500">Nobody reports to them.</p>
                    <ul v-else class="divide-y divide-gray-100">
                        <li v-for="r in reports" :key="r.id">
                            <Link :href="route('people.show', r.id)" class="flex items-center gap-2 py-2 hover:text-indigo-600">
                                <Avatar :name="r.name" size="sm" /><span class="text-sm font-medium">{{ r.name }}</span><span class="ml-auto text-xs text-gray-500">{{ r.role }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>

        <Modal :show="editing" max-width="lg" @close="editing = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="text-lg font-semibold text-gray-900">Edit {{ person.name }}</h2>
                <div class="mt-4 space-y-4">
                    <div v-if="abilities.change_role">
                        <label class="block text-sm font-medium text-gray-700" for="role">Role</label>
                        <SelectInput id="role" v-model="form.role_id" :options="roleOptions" class="mt-1" />
                        <InputError class="mt-1" :message="form.errors.role_id" />
                    </div>
                    <div v-if="abilities.change_manager">
                        <label class="block text-sm font-medium text-gray-700" for="manager">Reports to</label>
                        <SelectInput id="manager" v-model="form.reports_to_id" :options="managerOptions" class="mt-1" />
                        <InputError class="mt-1" :message="form.errors.reports_to_id" />
                    </div>
                    <template v-if="abilities.edit">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="department">Department</label>
                            <SelectInput id="department" v-model="form.department_id" :options="departmentOptions" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.department_id" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="designation">Designation</label>
                                <input id="designation" v-model="form.designation" type="text" :class="input" />
                                <InputError class="mt-1" :message="form.errors.designation" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="employee_code">Employee code</label>
                                <input id="employee_code" v-model="form.employee_code" type="text" :class="input" />
                                <InputError class="mt-1" :message="form.errors.employee_code" />
                            </div>
                        </div>
                    </template>
                    <InputError :message="form.errors.member" />
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <Btn variant="secondary" @click="editing = false">Cancel</Btn>
                    <Btn type="submit" :loading="form.processing">Save</Btn>
                </div>
            </form>
        </Modal>

        <ConfirmDialog :show="cancelling" title="Cancel the scheduled exit?" :message="`${person.name} will stay with the company.`" confirm-text="Cancel exit" @confirm="cancelExit" @cancel="cancelling = false" />
    </AppLayout>
</template>
