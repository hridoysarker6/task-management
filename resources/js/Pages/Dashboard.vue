<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <PageHeader :title="`Welcome, ${page.props.auth.user.name}`" subtitle="Your role-based dashboard will appear here." />

        <EmptyState
            title="Nothing to show yet"
            description="Projects, tasks and progress widgets will be added in the next phases."
        />
    </AppLayout>
</template>
