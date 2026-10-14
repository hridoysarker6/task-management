<script setup>
import InputError from '@/Components/InputError.vue';
import Btn from '@/Components/UI/Btn.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    state: { type: String, required: true }, // usable | invalid | expired | accepted | revoked
    token: { type: String, default: null },
    invitation: { type: Object, default: null },
    accountExists: { type: Boolean, default: false },
    signedInAs: { type: String, default: null },
    emailMatches: { type: Boolean, default: false },
});

const form = useForm({ name: props.invitation?.name ?? '', password: '', password_confirmation: '' });

const accept = () => form.post(route('invitations.accept', props.token), { onFinish: () => form.reset('password', 'password_confirmation') });

const messages = {
    invalid: ['This link is not valid', 'Check that you copied the whole link from the email.'],
    expired: ['This invitation has expired', 'Ask the person who invited you to send a new one.'],
    accepted: ['Already accepted', 'You have already joined. Sign in to continue.'],
    revoked: ['Invitation withdrawn', 'This invitation was cancelled by the company.'],
};

const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
</script>

<template>
    <GuestLayout>
        <Head title="Accept invitation" />

        <div v-if="state !== 'usable'" class="py-6 text-center">
            <h1 class="text-lg font-semibold text-gray-900">{{ messages[state]?.[0] ?? messages.invalid[0] }}</h1>
            <p class="mt-2 text-sm text-gray-600">{{ messages[state]?.[1] ?? messages.invalid[1] }}</p>
            <Link :href="route('login')" class="mt-4 inline-block text-sm font-medium text-indigo-600 hover:underline">Go to sign in</Link>
        </div>

        <div v-else class="py-2">
            <h1 class="text-lg font-semibold text-gray-900">Join {{ invitation.company }}</h1>
            <p class="mt-1 text-sm text-gray-600">
                You have been invited as <strong>{{ invitation.role }}</strong> using <strong>{{ invitation.email }}</strong>.
            </p>

            <!-- Already has an account: must be signed in as the invited email -->
            <template v-if="accountExists">
                <div v-if="emailMatches" class="mt-5">
                    <form @submit.prevent="accept">
                        <InputError :message="form.errors.invitation" />
                        <Btn type="submit" class="w-full" :loading="form.processing">Join {{ invitation.company }}</Btn>
                    </form>
                </div>
                <div v-else class="mt-5 space-y-3 text-sm text-gray-600">
                    <p v-if="signedInAs">You are signed in as <strong>{{ signedInAs }}</strong>. Sign out and sign in as <strong>{{ invitation.email }}</strong> to accept.</p>
                    <p v-else>You already have an account with this email. Sign in to accept the invitation.</p>
                    <Link v-if="signedInAs" :href="route('logout')" method="post" as="button" class="font-medium text-indigo-600 hover:underline">Sign out</Link>
                    <Btn v-else :href="route('login')" class="w-full">Sign in</Btn>
                </div>
            </template>

            <!-- New person: choose a name and password -->
            <form v-else class="mt-5 space-y-4" @submit.prevent="accept">
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="name">Your name</label>
                    <input id="name" v-model="form.name" type="text" required autofocus autocomplete="name" :class="input" />
                    <InputError class="mt-1" :message="form.errors.name" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="password">Choose a password</label>
                    <input id="password" v-model="form.password" type="password" required autocomplete="new-password" :class="input" />
                    <InputError class="mt-1" :message="form.errors.password" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password" :class="input" />
                </div>
                <InputError :message="form.errors.invitation" />
                <Btn type="submit" class="w-full" :loading="form.processing">Create account and join</Btn>
            </form>
        </div>
    </GuestLayout>
</template>
