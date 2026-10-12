<script setup>
import RoleMatrix from '@/Components/Roles/RoleMatrix.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    roles: { type: Array, required: true },
    modules: { type: Array, required: true },
    scopes: { type: Array, required: true },
    abilities: { type: Object, required: true },
});

const urls = {
    matrix: route('roles.matrix'),
    store: route('roles.store'),
    update: (id) => route('roles.update', id),
    destroy: (id) => route('roles.destroy', id),
};
</script>

<template>
    <Head title="Roles & Permissions" />

    <AppLayout>
        <PageHeader title="Roles & Permissions" subtitle="Control what each role can do and whose data it can see." />
        <RoleMatrix :roles="roles" :modules="modules" :scopes="scopes" :abilities="abilities" :urls="urls" />
    </AppLayout>
</template>
