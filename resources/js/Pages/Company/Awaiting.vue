<script setup>
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import FlashMessages from '@/Components/UI/FlashMessages.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    organization: { type: Object, required: true }, // { name, status, statusLabel, canEdit }
    note: { type: String, default: null },
});

const content = computed(
    () =>
        ({
            pending: {
                title: 'Awaiting approval',
                color: 'yellow',
                text: 'Thank you! Your application is with our team. You will get an email as soon as it has been reviewed. You do not need to do anything else.',
            },
            info_requested: {
                title: 'More information needed',
                color: 'blue',
                text: 'Our team needs a little more information before they can approve your company.',
            },
            rejected: {
                title: 'Application not approved',
                color: 'red',
                text: 'We could not approve this application. You can correct the details and send it back for another review.',
            },
        })[props.organization.status],
);
</script>

<template>
    <GuestLayout>
        <Head title="Awaiting approval" />
        <FlashMessages />

        <div class="py-4 text-center">
            <Badge :color="content.color">{{ organization.statusLabel }}</Badge>
            <h1 class="mt-3 text-xl font-semibold text-gray-900">{{ content.title }}</h1>
            <p class="mt-1 text-sm font-medium text-gray-700">{{ organization.name }}</p>
            <p class="mt-3 text-sm text-gray-600">{{ content.text }}</p>

            <div v-if="note" class="mt-4 rounded-lg bg-gray-50 p-3 text-left text-sm text-gray-700">
                <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Message from our team</div>
                <p class="whitespace-pre-line">{{ note }}</p>
            </div>

            <div class="mt-6 flex flex-col items-center gap-3">
                <Btn v-if="organization.canEdit" :href="route('application.edit')">Edit and resubmit</Btn>
                <Link :href="route('logout')" method="post" as="button" class="text-sm text-gray-500 underline hover:text-gray-800">Sign out</Link>
            </div>
        </div>
    </GuestLayout>
</template>
