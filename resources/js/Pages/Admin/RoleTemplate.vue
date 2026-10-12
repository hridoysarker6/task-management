<script setup>
import RoleMatrix from '@/Components/Roles/RoleMatrix.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    roles: { type: Array, required: true },
    modules: { type: Array, required: true },
    scopes: { type: Array, required: true },
    abilities: { type: Object, required: true },
});

const urls = {
    matrix: route('admin.role-template.matrix'),
    store: route('admin.role-template.roles.store'),
    update: (id) => route('admin.role-template.roles.update', id),
    destroy: (id) => route('admin.role-template.roles.destroy', id),
};
</script>

<template>
    <Head title="Default roles" />

    <AdminLayout>
        <PageHeader
            title="Default role template"
            subtitle="Every newly approved company starts with a copy of these roles. Changes do not affect companies that are already active."
        />
        <RoleMatrix :roles="roles" :modules="modules" :scopes="scopes" :abilities="abilities" :urls="urls" />
    </AdminLayout>
</template>
