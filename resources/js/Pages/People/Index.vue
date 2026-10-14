<script setup>
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowUpTrayIcon, UserPlusIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    tab: { type: String, required: true },
    rows: { type: Object, required: true }, // paginator
    filters: { type: Object, required: true },
    counts: { type: Object, required: true },
    roles: { type: Array, required: true },
    departments: { type: Array, required: true },
    abilities: { type: Object, required: true },
    seats: { type: Object, required: true }, // { limit, used }
});

const tabs = computed(() =>
    [
        { key: 'active', label: 'Active', count: props.counts.active },
        { key: 'invited', label: 'Invited', count: props.counts.invited },
        { key: 'former', label: 'Former employees', count: props.counts.former },
    ].filter((t) => t.count !== null),
);

const q = ref(props.filters.q);
const roleId = ref(props.filters.role_id);
const departmentId = ref(props.filters.department_id);

const go = () =>
    router.get(
        route('people.index'),
        { tab: props.tab, q: q.value || undefined, role_id: roleId.value || undefined, department_id: departmentId.value || undefined },
        { preserveState: true, replace: true },
    );
let timer;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(go, 300);
});
watch([roleId, departmentId], go);

const roleOptions = computed(() => [{ value: null, label: 'All roles' }, ...props.roles.map((r) => ({ value: r.id, label: r.name }))]);
const departmentOptions = computed(() => [{ value: null, label: 'All departments' }, ...props.departments.map((d) => ({ value: d.id, label: d.name }))]);

const columns = computed(() => {
    if (props.tab === 'invited') {
        return [
            { key: 'email', label: 'Invitee' },
            { key: 'role', label: 'Role' },
            { key: 'department', label: 'Department' },
            { key: 'invited_by', label: 'Invited by' },
            { key: 'state', label: 'Status' },
            { key: 'actions', label: '', class: 'text-right' },
        ];
    }
    const base = [
        { key: 'name', label: 'Person' },
        { key: 'role', label: 'Role' },
        { key: 'department', label: 'Department' },
        { key: 'manager', label: 'Reports to' },
    ];
    return props.tab === 'former'
        ? [...base, { key: 'left_at', label: 'Left' }, { key: 'exit_type', label: 'Exit' }]
        : [...base, { key: 'joined_at', label: 'Joined' }];
});

const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const exitLabel = { resigned: 'Resigned', terminated: 'Terminated', contract_ended: 'Contract ended' };
const stateColor = { pending: 'yellow', expired: 'red' };
const stateLabel = { pending: 'Pending', expired: 'Expired' };

const revoking = ref(null);
const resend = (i) => router.post(route('people.invitations.resend', i.id), {}, { preserveScroll: true });
const revoke = () => router.delete(route('people.invitations.destroy', revoking.value.id), { preserveScroll: true, onFinish: () => (revoking.value = null) });
</script>

<template>
    <Head title="People" />

    <AppLayout>
        <PageHeader title="People" :subtitle="seats.limit ? `${seats.used} of ${seats.limit} seats used` : 'Everyone in your company.'">
            <template #actions>
                <Btn v-if="abilities.import" variant="secondary" :href="route('people.import.create')"><ArrowUpTrayIcon class="h-4 w-4" /> Import CSV</Btn>
                <Btn v-if="abilities.invite" :href="route('people.invite')"><UserPlusIcon class="h-4 w-4" /> Invite</Btn>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <nav class="flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1" aria-label="People">
                <Link
                    v-for="t in tabs"
                    :key="t.key"
                    :href="route('people.index', { tab: t.key })"
                    :class="['flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium', tab === t.key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900']"
                >
                    {{ t.label }}
                    <span class="rounded-full bg-gray-200 px-1.5 text-xs text-gray-600">{{ t.count }}</span>
                </Link>
            </nav>

            <div class="flex flex-wrap items-center gap-2">
                <input v-model="q" type="search" placeholder="Search name, email or code…" class="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Search people" />
                <SelectInput v-model="roleId" :options="roleOptions" class="!w-40" aria-label="Filter by role" />
                <SelectInput v-model="departmentId" :options="departmentOptions" class="!w-44" aria-label="Filter by department" />
            </div>
        </div>

        <DataTable :columns="columns" :rows="rows.data" :empty-text="tab === 'invited' ? 'No open invitations.' : 'Nobody found.'">
            <template #cell-name="{ row }">
                <Link :href="route('people.show', row.id)" class="flex items-center gap-3">
                    <Avatar :name="row.name" :former="tab === 'former'" />
                    <span>
                        <span class="block font-medium text-gray-900 hover:text-indigo-600">{{ row.name }}</span>
                        <span class="block text-xs text-gray-500">{{ row.email }}</span>
                    </span>
                </Link>
            </template>
            <template #cell-role="{ row }">
                {{ row.role }}
                <Badge v-if="row.user_type === 'external'" color="purple" class="ml-1">External</Badge>
            </template>
            <template #cell-department="{ row }">{{ row.department ?? '—' }}</template>
            <template #cell-manager="{ row }">{{ row.manager ?? '—' }}</template>
            <template #cell-joined_at="{ row }">
                {{ fmt(row.joined_at) }}
                <Badge v-if="row.scheduled_exit_at" color="yellow" class="ml-1">Leaves {{ fmt(row.scheduled_exit_at) }}</Badge>
            </template>
            <template #cell-left_at="{ row }">{{ fmt(row.left_at) }}</template>
            <template #cell-exit_type="{ row }">{{ exitLabel[row.exit_type] ?? '—' }}</template>

            <template #cell-email="{ row }">
                <div class="font-medium text-gray-900">{{ row.email }}</div>
                <div v-if="row.name" class="text-xs text-gray-500">{{ row.name }}</div>
            </template>
            <template #cell-invited_by="{ row }">{{ row.invited_by ?? '—' }}</template>
            <template #cell-state="{ row }">
                <Badge :color="stateColor[row.state]">{{ stateLabel[row.state] }}</Badge>
                <div class="mt-0.5 text-xs text-gray-400">{{ row.state === 'expired' ? 'Expired' : 'Expires' }} {{ fmt(row.expires_at.slice(0, 10)) }}</div>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1">
                    <Btn size="sm" variant="secondary" @click="resend(row)">Resend</Btn>
                    <Btn size="sm" variant="ghost" @click="revoking = row">Revoke</Btn>
                </div>
            </template>

            <template #footer><Pagination :links="rows.links" /></template>
        </DataTable>

        <ConfirmDialog
            :show="!!revoking"
            title="Revoke this invitation?"
            :message="revoking ? `${revoking.email} will no longer be able to join with this link.` : ''"
            confirm-text="Revoke"
            danger
            @confirm="revoke"
            @cancel="revoking = null"
        />
    </AppLayout>
</template>
