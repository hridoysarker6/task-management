<script setup>
import Btn from '@/Components/UI/Btn.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    preferences: { type: Array, required: true }, // [{ event, label, description, app, mail }]
});

const form = useForm({ preferences: Object.fromEntries(props.preferences.map((p) => [p.event, { app: p.app, mail: p.mail }])) });
const save = () => form.put(route('notifications.preferences.update'), { preserveScroll: true });
</script>

<template>
    <Head title="Notification settings" />

    <AppLayout>
        <PageHeader title="Notification settings" subtitle="Choose what reaches your bell and your inbox. This applies to this company." />

        <form class="max-w-3xl" @submit.prevent="save">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-4 py-2 text-left">When</th><th class="w-24 px-3 py-2 text-center">In the app</th><th class="w-24 px-3 py-2 text-center">Email</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="p in preferences" :key="p.event">
                            <td class="px-4 py-3"><span class="block font-medium text-gray-900">{{ p.label }}</span><span class="block text-xs text-gray-500">{{ p.description }}</span></td>
                            <td class="px-3 py-3 text-center"><input v-model="form.preferences[p.event].app" type="checkbox" :aria-label="`${p.label}: in the app`" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /></td>
                            <td class="px-3 py-3 text-center"><input v-model="form.preferences[p.event].mail" type="checkbox" :aria-label="`${p.label}: by email`" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <Btn type="submit" :loading="form.processing">Save settings</Btn>
                <Link :href="route('notifications.index')" class="text-sm text-gray-500 hover:text-gray-800">Back to notifications</Link>
                <span v-if="form.recentlySuccessful" class="text-sm text-green-600">Saved.</span>
            </div>
        </form>
    </AppLayout>
</template>
