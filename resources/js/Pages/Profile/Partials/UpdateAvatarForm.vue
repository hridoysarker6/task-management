<script setup>
import InputError from '@/Components/InputError.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Btn from '@/Components/UI/Btn.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

// Profile photo: a small image shown next to your name. Without one, your initials are shown.
const user = usePage().props.auth.user;
const form = useForm({ avatar: null });
const file = ref(null);

const choose = (e) => {
    form.avatar = e.target.files[0] ?? null;
    if (form.avatar) form.post(route('profile.avatar.update'), { preserveScroll: true, forceFormData: true, onSuccess: () => (file.value.value = '') });
};
const remove = () => router.delete(route('profile.avatar.destroy'), { preserveScroll: true });
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Photo</h2>
            <p class="mt-1 text-sm text-gray-600">A square picture works best. PNG, JPG or WebP, up to 2 MB.</p>
        </header>

        <div class="mt-4 flex items-center gap-4">
            <Avatar :name="user.name" :src="user.avatar_url" size="lg" />
            <div class="flex flex-wrap items-center gap-2">
                <label class="inline-flex cursor-pointer items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-within:ring-2 focus-within:ring-indigo-500">
                    {{ form.processing ? 'Uploading…' : user.avatar_url ? 'Change photo' : 'Upload photo' }}
                    <input ref="file" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="choose" />
                </label>
                <Btn v-if="user.avatar_url" variant="ghost" size="sm" @click="remove">Remove</Btn>
            </div>
        </div>
        <InputError class="mt-2" :message="form.errors.avatar" />
    </section>
</template>
