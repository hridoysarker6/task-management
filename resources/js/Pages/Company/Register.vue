<script setup>
import InputError from '@/Components/InputError.vue';
import Btn from '@/Components/UI/Btn.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import CompanyFields from '@/Pages/Company/Partials/CompanyFields.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    sizes: { type: Array, required: true },
    maxFileMb: { type: Number, default: 5 },
    maxFiles: { type: Number, default: 5 },
});

const form = useForm({
    // company
    name: '',
    slug: '',
    industry: '',
    size: null,
    address: '',
    phone: '',
    email: '',
    website: '',
    registration_no: '',
    trade_license: null,
    other_documents: [],
    // contact person (becomes the Company Admin)
    contact_name: '',
    contact_designation: '',
    contact_email: '',
    contact_phone: '',
    password: '',
    password_confirmation: '',
    accept_terms: false,
});

const submit = () => {
    form.post(route('register.store'), {
        forceFormData: true,
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <GuestLayout wide>
        <Head title="Register your company" />

        <h1 class="text-xl font-semibold text-gray-900">Register your company</h1>
        <p class="mt-1 text-sm text-gray-500">
            Tell us about your company and who will administer it. After you verify your email, our team reviews the application.
        </p>

        <form class="mt-6 space-y-8" @submit.prevent="submit">
            <section>
                <h2 class="mb-3 border-b border-gray-100 pb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Company</h2>
                <CompanyFields :form="form" :sizes="sizes" :max-file-mb="maxFileMb" :max-files="maxFiles" />
            </section>

            <section>
                <h2 class="mb-3 border-b border-gray-100 pb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Contact person (Company Admin)</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="contact_name">Full name</label>
                        <input id="contact_name" v-model="form.contact_name" type="text" required autocomplete="name" :class="input" />
                        <InputError class="mt-1" :message="form.errors.contact_name" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="contact_designation">Designation</label>
                        <input id="contact_designation" v-model="form.contact_designation" type="text" :class="input" />
                        <InputError class="mt-1" :message="form.errors.contact_designation" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="contact_email">Email (used to sign in)</label>
                        <input id="contact_email" v-model="form.contact_email" type="email" required autocomplete="username" :class="input" />
                        <InputError class="mt-1" :message="form.errors.contact_email" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="contact_phone">Phone</label>
                        <input id="contact_phone" v-model="form.contact_phone" type="tel" autocomplete="tel" :class="input" />
                        <InputError class="mt-1" :message="form.errors.contact_phone" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="password">Password</label>
                        <input id="password" v-model="form.password" type="password" required autocomplete="new-password" :class="input" />
                        <InputError class="mt-1" :message="form.errors.password" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password" :class="input" />
                    </div>
                </div>
            </section>

            <div>
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input v-model="form.accept_terms" type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    <span>I confirm the information is correct and I accept the platform terms of use.</span>
                </label>
                <InputError class="mt-1" :message="form.errors.accept_terms" />
            </div>

            <div class="flex items-center justify-between gap-3 pb-2">
                <Link :href="route('login')" class="text-sm text-gray-600 underline hover:text-gray-900">Already registered? Sign in</Link>
                <Btn type="submit" :loading="form.processing">Submit application</Btn>
            </div>
        </form>
    </GuestLayout>
</template>
