<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import Btn from '@/Components/UI/Btn.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <div class="mb-8">
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Welcome back</h1>
            <p class="mt-1.5 text-sm text-gray-500">Log in to pick up where you left off.</p>
        </div>

        <div v-if="status" class="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
            {{ status }}
        </div>

        <div v-if="$page.props.flash.error" class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/20" role="alert">
            {{ $page.props.flash.error }}
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" v-model="form.email" type="email" placeholder="you@company.com" required autofocus autocomplete="username" />
                <InputError :message="form.errors.email" />
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <InputLabel for="password" value="Password" />
                    <Link v-if="canResetPassword" :href="route('password.request')" class="mb-1.5 rounded text-sm font-medium text-violet-600 hover:text-violet-700">Forgot password?</Link>
                </div>
                <TextInput id="password" v-model="form.password" type="password" placeholder="••••••••" required autocomplete="current-password" />
                <InputError :message="form.errors.password" />
            </div>

            <label class="flex cursor-pointer items-center gap-2.5">
                <Checkbox v-model:checked="form.remember" name="remember" />
                <span class="text-sm text-gray-600">Keep me logged in</span>
            </label>

            <Btn type="submit" size="lg" class="w-full" :loading="form.processing">Log in</Btn>
        </form>

        <p class="mt-8 border-t border-gray-100 pt-6 text-center text-sm text-gray-500">
            New to Task Pro?
            <Link :href="route('register')" class="font-semibold text-violet-600 hover:text-violet-700">Register your company</Link>
        </p>
    </GuestLayout>
</template>
