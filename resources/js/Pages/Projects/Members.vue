<script setup>
import InputError from '@/Components/InputError.vue';
import ProjectHeader from '@/Components/Projects/ProjectHeader.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    members: { type: Array, required: true },
    former: { type: Array, required: true },
    candidates: { type: Array, required: true },
    roles: { type: Array, required: true },
});

const canManage = computed(() => props.project.abilities.manage_members);
const roleLabel = { manager: 'Project manager', member: 'Member', stakeholder: 'Stakeholder', viewer: 'Viewer' };
const roleOptions = props.roles.map((r) => ({ value: r, label: roleLabel[r] }));
const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

// --- Add -----------------------------------------------------------------------------------
const form = useForm({ membership_id: null, project_role: 'member' });
const candidate = computed(() => props.candidates.find((c) => c.id === form.membership_id));
const candidateOptions = computed(() => [
    { value: null, label: 'Choose a person…' },
    ...props.candidates.map((c) => ({ value: c.id, label: `${c.name} (${c.role}${c.external ? ', external' : ''})` })),
]);
// External people can only be stakeholders or viewers.
const rolesFor = computed(() => (candidate.value?.external ? roleOptions.filter((r) => r.value !== 'member') : roleOptions));
const pickPerson = (id) => {
    form.membership_id = id;
    if (props.candidates.find((c) => c.id === id)?.external && form.project_role === 'member') form.project_role = 'stakeholder';
};
const add = () => form.post(route('projects.members.store', props.project.id), { preserveScroll: true, onSuccess: () => form.reset() });

// --- Change role / remove --------------------------------------------------------------------
const setRole = (m, role) => router.put(route('projects.members.update', [props.project.id, m.id]), { project_role: role }, { preserveScroll: true });
const removing = ref(null);
const remove = () => router.delete(route('projects.members.destroy', [props.project.id, removing.value.id]), { preserveScroll: true, onFinish: () => (removing.value = null) });
</script>

<template>
    <Head :title="`${project.key} · Members`" />

    <AppLayout>
        <ProjectHeader :project="project" active="members" />

        <form v-if="canManage" class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4" @submit.prevent="add">
            <div class="min-w-[16rem] flex-1">
                <label class="block text-sm font-medium text-gray-700" for="person">Add a person</label>
                <SelectInput id="person" :model-value="form.membership_id" :options="candidateOptions" class="mt-1" @update:model-value="pickPerson" />
            </div>
            <div class="w-44">
                <label class="block text-sm font-medium text-gray-700" for="role">Project role</label>
                <SelectInput id="role" v-model="form.project_role" :options="rolesFor" class="mt-1" />
            </div>
            <Btn type="submit" :disabled="!form.membership_id" :loading="form.processing">Add</Btn>
            <div class="w-full"><InputError :message="form.errors.membership_id || form.errors.project_role || form.errors.project" /></div>
        </form>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <ul class="divide-y divide-gray-100">
                <li v-for="m in members" :key="m.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <Avatar :name="m.name" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 text-sm font-medium text-gray-900">{{ m.name }} <Badge v-if="m.external" color="purple">External</Badge></div>
                        <div class="text-xs text-gray-500">{{ m.email }} &middot; joined {{ fmt(m.joined_at) }}</div>
                    </div>

                    <Badge v-if="m.project_role === 'manager'" color="indigo">Project manager</Badge>
                    <SelectInput
                        v-else-if="canManage"
                        :model-value="m.project_role"
                        :options="m.external ? roleOptions.filter((r) => r.value !== 'member') : roleOptions"
                        class="!w-36 !py-1 !text-xs"
                        :aria-label="`Role of ${m.name}`"
                        @update:model-value="(r) => setRole(m, r)"
                    />
                    <Badge v-else color="gray">{{ roleLabel[m.project_role] }}</Badge>

                    <Btn v-if="canManage && m.project_role !== 'manager'" size="sm" variant="ghost" @click="removing = m">Remove</Btn>
                </li>
                <li v-if="!members.length" class="px-4 py-8 text-center text-sm text-gray-500">Nobody is in this project yet.</li>
            </ul>
        </section>

        <section v-if="former.length" class="mt-6">
            <h2 class="mb-2 text-sm font-semibold text-gray-900">Previous members</h2>
            <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                <li v-for="m in former" :key="m.id" class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-500">
                    <Avatar :name="m.name" size="sm" :former="true" />
                    <span class="font-medium text-gray-700">{{ m.name }}<template v-if="m.is_former_employee"> (Former)</template></span>
                    <span class="ml-auto text-xs">{{ roleLabel[m.project_role] }} &middot; left {{ fmt(m.left_at) }}</span>
                </li>
            </ul>
            <p v-if="canManage" class="mt-2 text-xs text-gray-400">Add someone again from the list above to bring them back.</p>
        </section>

        <ConfirmDialog :show="!!removing" title="Remove from project?" :message="removing ? `${removing.name} will lose access to this project. Their history stays.` : ''" confirm-text="Remove" danger @confirm="remove" @cancel="removing = null" />
    </AppLayout>
</template>
