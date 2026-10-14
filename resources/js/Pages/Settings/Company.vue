<script setup>
import InputError from '@/Components/InputError.vue';
import Btn from '@/Components/UI/Btn.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    company: { type: Object, required: true },
    timezones: { type: Array, required: true },
    dateFormats: { type: Array, required: true },
    days: { type: Array, required: true },
    canEdit: { type: Boolean, default: false },
});

const form = useForm({
    timezone: props.company.timezone,
    date_format: props.company.date_format,
    working_days: [...props.company.working_days],
    logo: null,
    remove_logo: false,
});

const preview = ref(null);
const onLogo = (e) => {
    form.logo = e.target.files[0] ?? null;
    form.remove_logo = false;
    preview.value = form.logo ? URL.createObjectURL(form.logo) : null;
};
const logoSrc = computed(() => (form.remove_logo ? null : (preview.value ?? props.company.logo_url)));

const toggleDay = (d) => {
    const i = form.working_days.indexOf(d);
    i === -1 ? form.working_days.push(d) : form.working_days.splice(i, 1);
};

const timezoneOptions = computed(() => props.timezones.map((t) => ({ value: t, label: t })));
const submit = () => form.post(route('settings.company.update'), { forceFormData: true, preserveScroll: true, onSuccess: () => ((preview.value = null), form.reset('logo', 'remove_logo')) });
</script>

<template>
    <Head title="Company settings" />

    <AppLayout>
        <PageHeader :title="company.name" subtitle="Company settings" />

        <form class="max-w-2xl space-y-6" @submit.prevent="submit">
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Logo</h2>
                <div class="flex items-center gap-4">
                    <div class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                        <img v-if="logoSrc" :src="logoSrc" alt="Company logo" class="h-full w-full object-contain" />
                        <span v-else class="text-xs text-gray-400">No logo</span>
                    </div>
                    <div v-if="canEdit" class="space-y-2">
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="block text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" aria-label="Upload logo" @change="onLogo" />
                        <label v-if="company.logo_url && !form.logo" class="flex items-center gap-2 text-sm text-gray-600">
                            <input v-model="form.remove_logo" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /> Remove current logo
                        </label>
                        <p class="text-xs text-gray-500">PNG, JPG or WebP, up to 2 MB.</p>
                    </div>
                </div>
                <InputError class="mt-2" :message="form.errors.logo" />
            </section>

            <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="timezone">Time zone</label>
                        <SelectInput id="timezone" v-model="form.timezone" :options="timezoneOptions" :disabled="!canEdit" class="mt-1" />
                        <InputError class="mt-1" :message="form.errors.timezone" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="date_format">Date format</label>
                        <SelectInput id="date_format" v-model="form.date_format" :options="dateFormats" :disabled="!canEdit" class="mt-1" />
                        <InputError class="mt-1" :message="form.errors.date_format" />
                    </div>
                </div>

                <fieldset>
                    <legend class="text-sm font-medium text-gray-700">Working days</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <label
                            v-for="d in days"
                            :key="d.value"
                            :class="['flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-sm', form.working_days.includes(d.value) ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600', !canEdit && 'cursor-not-allowed opacity-60']"
                        >
                            <input type="checkbox" class="sr-only" :checked="form.working_days.includes(d.value)" :disabled="!canEdit" @change="toggleDay(d.value)" />
                            {{ d.label }}
                        </label>
                    </div>
                    <InputError class="mt-1" :message="form.errors.working_days" />
                </fieldset>
            </section>

            <div v-if="canEdit" class="flex justify-end"><Btn type="submit" :loading="form.processing">Save settings</Btn></div>
        </form>
    </AppLayout>
</template>
