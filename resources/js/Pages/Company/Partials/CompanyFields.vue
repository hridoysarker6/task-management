<script setup>
import InputError from '@/Components/InputError.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import { computed, ref, watch } from 'vue';

/**
 * Company details + documents, shared by the registration form and the edit & resubmit form.
 * `form` is an Inertia form with: name, slug, industry, size, address, phone, email, website,
 * registration_no, trade_license, other_documents.
 */
const props = defineProps({
    form: { type: Object, required: true },
    sizes: { type: Array, required: true },
    maxFileMb: { type: Number, default: 5 },
    maxFiles: { type: Number, default: 5 },
    existingDocuments: { type: Array, default: () => [] },
    suggestSlug: { type: Boolean, default: true },
});

const input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
const slugify = (s) => s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 100);

// Suggest the short code from the name until the person edits it themselves.
const slugTouched = ref(!props.suggestSlug);
watch(() => props.form.name, (name) => {
    if (!slugTouched.value) props.form.slug = slugify(name);
});

// Errors for individual files arrive as `other_documents.0`, `other_documents.1`, ...
const fileErrors = computed(() => [
    ...new Set(Object.entries(props.form.errors).filter(([key]) => key.startsWith('other_documents.')).map(([, msg]) => msg)),
]);

const sizeOptions = [{ value: null, label: 'Select…' }, ...props.sizes.map((s) => ({ value: s, label: `${s} people` }))];

const onLicense = (e) => (props.form.trade_license = e.target.files[0] ?? null);
const onOthers = (e) => (props.form.other_documents = Array.from(e.target.files).slice(0, props.maxFiles));
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700" for="name">Company name</label>
            <input id="name" v-model="form.name" type="text" required autofocus :class="input" />
            <InputError class="mt-1" :message="form.errors.name" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700" for="slug">Short code</label>
            <input id="slug" v-model="form.slug" type="text" required :class="[input, 'font-mono']" @input="slugTouched = true" />
            <p class="mt-1 text-xs text-gray-500">Lowercase letters, numbers and dashes. Used in URLs.</p>
            <InputError class="mt-1" :message="form.errors.slug" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700" for="registration_no">Trade licence / registration no.</label>
            <input id="registration_no" v-model="form.registration_no" type="text" :class="input" />
            <InputError class="mt-1" :message="form.errors.registration_no" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700" for="industry">Industry</label>
            <input id="industry" v-model="form.industry" type="text" :class="input" />
            <InputError class="mt-1" :message="form.errors.industry" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700" for="size">Company size</label>
            <SelectInput id="size" v-model="form.size" :options="sizeOptions" class="mt-1" />
            <InputError class="mt-1" :message="form.errors.size" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700" for="phone">Company phone</label>
            <input id="phone" v-model="form.phone" type="tel" :class="input" />
            <InputError class="mt-1" :message="form.errors.phone" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700" for="email">Company email</label>
            <input id="email" v-model="form.email" type="email" :class="input" />
            <InputError class="mt-1" :message="form.errors.email" />
        </div>

        <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700" for="website">Website</label>
            <input id="website" v-model="form.website" type="url" placeholder="https://" :class="input" />
            <InputError class="mt-1" :message="form.errors.website" />
        </div>

        <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700" for="address">Address</label>
            <textarea id="address" v-model="form.address" rows="2" :class="input" />
            <InputError class="mt-1" :message="form.errors.address" />
        </div>

        <div class="sm:col-span-2">
            <h3 class="text-sm font-medium text-gray-700">Documents <span class="font-normal text-gray-400">(optional)</span></h3>
            <p class="text-xs text-gray-500">PDF, JPG or PNG, up to {{ maxFileMb }} MB each. Only the platform administrators can see them.</p>

            <ul v-if="existingDocuments.length" class="mt-2 space-y-1 text-sm text-gray-600">
                <li v-for="d in existingDocuments" :key="d.id">&#128206; {{ d.original_name }}</li>
            </ul>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700" for="trade_license">Trade licence</label>
            <input id="trade_license" type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" @change="onLicense" />
            <InputError class="mt-1" :message="form.errors.trade_license" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700" for="other_documents">Other documents</label>
            <input id="other_documents" type="file" multiple accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" @change="onOthers" />
            <InputError class="mt-1" :message="form.errors.other_documents" />
            <InputError v-for="msg in fileErrors" :key="msg" class="mt-1" :message="msg" />
        </div>
    </div>
</template>
