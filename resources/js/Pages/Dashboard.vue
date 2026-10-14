<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps({
    onboarding: { type: String, default: null }, // in_progress | done | null (not an admin)
});

const page = usePage();
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <PageHeader :title="`Welcome, ${page.props.auth.user.name}`" subtitle="Your role-based dashboard will appear here." />

        <div v-if="onboarding === 'in_progress'" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
            <p class="text-sm text-indigo-900"><strong>Finish setting up your company</strong>: departments, roles, people and hierarchy.</p>
            <Link :href="route('onboarding.show')" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">Continue setup</Link>
        </div>

        <EmptyState
            v-if="!page.props.currentOrganization"
            title="You are not part of a company yet"
            description="Ask your Company Admin to invite you, or register a new company."
        />
        <EmptyState
            v-else
            title="Nothing to show yet"
            description="Projects, tasks and progress widgets will be added in the next phases."
        />
    </AppLayout>
</template>
