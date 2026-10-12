<script setup>
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ArrowLeftIcon, DocumentIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    company: { type: Object, required: true },
    applicant: { type: Object, required: true },
    documents: { type: Array, required: true },
    reviews: { type: Array, required: true },
});

const statusColor = { pending: 'yellow', info_requested: 'blue', active: 'green', rejected: 'gray', suspended: 'red' };
const actionLabel = {
    submitted: 'Application submitted',
    resubmitted: 'Application resubmitted',
    approved: 'Approved',
    rejected: 'Rejected',
    info_requested: 'More information requested',
    suspended: 'Suspended',
    reactivated: 'Reactivated',
};

const fields = computed(() => [
    ['Short code', props.company.slug],
    ['Industry', props.company.industry],
    ['Size', props.company.size],
    ['Registration no.', props.company.registration_no],
    ['Company email', props.company.email],
    ['Phone', props.company.phone],
    ['Website', props.company.website],
    ['Address', props.company.address],
]);

const formatDateTime = (iso) =>
    iso ? new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const formatSize = (bytes) => (bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

// --- Actions ---------------------------------------------------------------------------------
const approving = ref(false);
const busy = ref(false);
const approve = () => {
    router.post(route('admin.companies.approve', props.company.id), {}, {
        onStart: () => (busy.value = true),
        onFinish: () => {
            busy.value = false;
            approving.value = false;
        },
    });
};

// One modal form reused for reject / request info / suspend / reactivate.
const modal = ref(null); // 'reject' | 'info' | 'suspend' | 'reactivate'
const noteForm = useForm({ reason: '', note: '' });
const modals = {
    reject: { title: 'Reject application', field: 'reason', required: true, label: 'Reason (sent to the applicant)', button: 'Reject', danger: true, routeName: 'admin.companies.reject' },
    info: { title: 'Request more information', field: 'note', required: true, label: 'What do you need? (sent to the applicant)', button: 'Send request', routeName: 'admin.companies.request-info' },
    suspend: { title: 'Suspend company', field: 'note', required: false, label: 'Reason (internal note, optional)', button: 'Suspend company', danger: true, routeName: 'admin.companies.suspend', help: 'Nobody in this company will be able to sign in until it is reactivated. Data is kept.' },
    reactivate: { title: 'Reactivate company', field: 'note', required: false, label: 'Note (optional)', button: 'Reactivate', routeName: 'admin.companies.reactivate' },
};
const current = computed(() => modals[modal.value]);

const openModal = (name) => {
    noteForm.reset();
    noteForm.clearErrors();
    modal.value = name;
};
const submitModal = () => {
    const m = current.value;
    noteForm
        .transform((data) => ({ [m.field]: data[m.field] }))
        .post(route(m.routeName, props.company.id), { preserveScroll: true, onSuccess: () => (modal.value = null) });
};

const limitForm = useForm({ user_limit: props.company.user_limit ?? '' });
const saveLimit = () => {
    limitForm.transform((d) => ({ user_limit: d.user_limit === '' ? null : d.user_limit })).patch(route('admin.companies.user-limit', props.company.id), { preserveScroll: true });
};
</script>

<template>
    <Head :title="company.name" />

    <AdminLayout>
        <Link :href="route('admin.companies.index', { status: company.status })" class="mb-3 inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900">
            <ArrowLeftIcon class="h-4 w-4" /> Companies
        </Link>

        <PageHeader :title="company.name">
            <template #actions>
                <Badge :color="statusColor[company.status]">{{ company.status_label }}</Badge>
                <template v-if="company.status === 'pending'">
                    <Btn variant="secondary" @click="openModal('info')">Request info</Btn>
                    <Btn variant="danger" @click="openModal('reject')">Reject</Btn>
                    <Btn :disabled="!applicant.email_verified" @click="approving = true">Approve</Btn>
                </template>
                <Btn v-if="company.status === 'active'" variant="danger" @click="openModal('suspend')">Suspend</Btn>
                <Btn v-if="company.status === 'suspended'" @click="openModal('reactivate')">Reactivate</Btn>
            </template>
        </PageHeader>

        <p v-if="company.status === 'pending' && !applicant.email_verified" class="mb-4 rounded-lg bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
            The contact person has not verified their email yet. You can approve once they do.
        </p>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Company</h2>
                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        <div v-for="[label, value] in fields" :key="label" :class="label === 'Address' ? 'sm:col-span-2' : ''">
                            <dt class="text-xs uppercase tracking-wide text-gray-400">{{ label }}</dt>
                            <dd class="mt-0.5 text-sm text-gray-800">{{ value || '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Documents</h2>
                    <p v-if="!documents.length" class="text-sm text-gray-500">No documents uploaded.</p>
                    <ul v-else class="divide-y divide-gray-100">
                        <li v-for="d in documents" :key="d.id" class="flex items-center justify-between gap-3 py-2">
                            <div class="flex min-w-0 items-center gap-2">
                                <DocumentIcon class="h-5 w-5 shrink-0 text-gray-400" />
                                <a :href="d.url" class="truncate text-sm font-medium text-indigo-600 hover:underline">{{ d.original_name }}</a>
                            </div>
                            <div class="flex shrink-0 items-center gap-2 text-xs text-gray-500">
                                <Badge :color="d.type === 'trade_license' ? 'indigo' : 'gray'">{{ d.type === 'trade_license' ? 'Trade licence' : 'Other' }}</Badge>
                                {{ formatSize(d.size) }}
                            </div>
                        </li>
                    </ul>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Review history</h2>
                    <ol class="space-y-4">
                        <li v-for="r in reviews" :key="r.id" class="border-l-2 border-gray-200 pl-4">
                            <div class="text-sm font-medium text-gray-900">{{ actionLabel[r.action] ?? r.action }}</div>
                            <div class="text-xs text-gray-500">{{ r.actor }} &middot; {{ formatDateTime(r.created_at) }}</div>
                            <p v-if="r.note" class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ r.note }}</p>
                        </li>
                    </ol>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Contact person</h2>
                    <div class="text-sm font-medium text-gray-900">{{ applicant.name }}</div>
                    <div v-if="company.contact_designation" class="text-xs text-gray-500">{{ company.contact_designation }}</div>
                    <div class="mt-2 text-sm text-gray-700">{{ applicant.email }}</div>
                    <div v-if="applicant.phone" class="text-sm text-gray-700">{{ applicant.phone }}</div>
                    <div class="mt-2">
                        <Badge :color="applicant.email_verified ? 'green' : 'yellow'">{{ applicant.email_verified ? 'Email verified' : 'Email unverified' }}</Badge>
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Usage</h2>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="shrink-0 text-gray-500">Registered</dt><dd class="text-right">{{ formatDateTime(company.created_at) }}</dd></div>
                        <div v-if="company.approved_at" class="flex justify-between gap-3"><dt class="shrink-0 text-gray-500">Approved</dt><dd class="text-right">{{ formatDateTime(company.approved_at) }}</dd></div>
                        <div v-if="company.approved_by" class="flex justify-between gap-3"><dt class="shrink-0 text-gray-500">Approved by</dt><dd class="text-right">{{ company.approved_by }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="shrink-0 text-gray-500">Active people</dt><dd class="text-right">{{ company.member_count }}</dd></div>
                    </dl>

                    <form v-if="company.status === 'active' || company.status === 'suspended'" class="mt-4 border-t border-gray-100 pt-4" @submit.prevent="saveLimit">
                        <label for="user-limit" class="block text-sm font-medium text-gray-700">User limit</label>
                        <div class="mt-1 flex gap-2">
                            <input
                                id="user-limit"
                                v-model="limitForm.user_limit"
                                type="number"
                                min="1"
                                placeholder="Unlimited"
                                class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <Btn type="submit" variant="secondary" :loading="limitForm.processing">Save</Btn>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Leave empty for no limit. Former employees do not count.</p>
                        <InputError class="mt-1" :message="limitForm.errors.user_limit" />
                    </form>
                </section>
            </aside>
        </div>

        <ConfirmDialog
            :show="approving"
            title="Approve this company?"
            :message="`${company.name} will be activated and ${applicant.name} becomes its Company Admin. They will be emailed.`"
            confirm-text="Approve"
            :loading="busy"
            @confirm="approve"
            @cancel="approving = false"
        />

        <Modal :show="!!modal" max-width="lg" @close="modal = null">
            <form v-if="current" class="p-6" @submit.prevent="submitModal">
                <h2 class="text-lg font-semibold text-gray-900">{{ current.title }}</h2>
                <p v-if="current.help" class="mt-1 text-sm text-gray-500">{{ current.help }}</p>
                <label class="mt-4 block text-sm font-medium text-gray-700" for="note">{{ current.label }}</label>
                <textarea
                    id="note"
                    v-model="noteForm[current.field]"
                    rows="4"
                    :required="current.required"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
                <InputError class="mt-1" :message="noteForm.errors[current.field] || noteForm.errors.status" />
                <div class="mt-6 flex justify-end gap-2">
                    <Btn variant="secondary" @click="modal = null">Cancel</Btn>
                    <Btn type="submit" :variant="current.danger ? 'danger' : 'primary'" :loading="noteForm.processing">{{ current.button }}</Btn>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
