<script setup>
import InputError from '@/Components/InputError.vue';
import Btn from '@/Components/UI/Btn.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import CompanyFields from '@/Pages/Company/Partials/CompanyFields.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    organization: { type: Object, required: true },
    documents: { type: Array, default: () => [] },
    note: { type: String, default: null },
    sizes: { type: Array, required: true },
    maxFileMb: { type: Number, default: 5 },
    maxFiles: { type: Number, default: 5 },
});

const o = props.organization;
const form = useForm({
    name: o.name ?? '',
    slug: o.slug ?? '',
    industry: o.industry ?? '',
    size: o.size ?? null,
    address: o.address ?? '',
    phone: o.phone ?? '',
    email: o.email ?? '',
    website: o.website ?? '',
    registration_no: o.registration_no ?? '',
    trade_license: null,
    other_documents: [],
    note: '',
});

// Multipart bodies are not parsed on PUT, so post with method spoofing.
const submit = () =>
    form
        .transform((data) => ({ ...data, _method: 'put' }))
        .post(route('application.update'), { forceFormData: true });
</script>

<template>
    <GuestLayout wide>
        <Head title="Update your application" />

        <h1 class="text-xl font-semibold text-gray-900">Update your application</h1>

        <div v-if="note" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-amber-700">Message from our team</div>
            <p class="whitespace-pre-line">{{ note }}</p>
        </div>

        <form class="mt-6 space-y-6" @submit.prevent="submit">
            <CompanyFields :form="form" :sizes="sizes" :max-file-mb="maxFileMb" :max-files="maxFiles" :existing-documents="documents" :suggest-slug="false" />

            <div>
                <label class="block text-sm font-medium text-gray-700" for="note">What did you change? <span class="font-normal text-gray-400">(optional)</span></label>
                <textarea id="note" v-model="form.note" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                <InputError class="mt-1" :message="form.errors.note" />
            </div>

            <div class="flex items-center justify-between gap-3 pb-2">
                <Link :href="route('application.show')" class="text-sm text-gray-600 underline hover:text-gray-900">Cancel</Link>
                <Btn type="submit" :loading="form.processing">Resubmit application</Btn>
            </div>
        </form>
    </GuestLayout>
</template>
