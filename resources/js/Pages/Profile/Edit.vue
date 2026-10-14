<script setup>
import EmploymentHistory from '@/Components/People/EmploymentHistory.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    employment: {
        type: Object,
        default: null,
    },
});
</script>

<template>
    <Head title="Profile" />

    <AppLayout>
        <PageHeader title="Profile" subtitle="Manage your account details and password." />

        <div class="max-w-3xl space-y-6">
            <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-8">
                <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" class="max-w-xl" />
            </div>

            <div v-if="employment" class="rounded-xl border border-gray-200 bg-white p-4 sm:p-8">
                <h2 class="text-lg font-medium text-gray-900">Employment history</h2>
                <p class="mb-4 mt-1 text-sm text-gray-600">
                    {{ employment.company }} &middot; {{ employment.role }}<template v-if="employment.manager"> &middot; reports to {{ employment.manager }}</template>
                </p>
                <EmploymentHistory :periods="employment.periods" />
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-8">
                <UpdatePasswordForm class="max-w-xl" />
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-8">
                <DeleteUserForm class="max-w-xl" />
            </div>
        </div>
    </AppLayout>
</template>