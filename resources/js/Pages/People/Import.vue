<script setup>
import InputError from '@/Components/InputError.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ArrowDownTrayIcon, CheckCircleIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    stage: { type: String, required: true }, // upload | preview | result
    columns: { type: Array, default: () => [] },
    maxRows: { type: Number, default: 500 },
    token: { type: String, default: null },
    rows: { type: Array, default: () => [] },
    validCount: { type: Number, default: 0 },
    invalidCount: { type: Number, default: 0 },
    created: { type: Number, default: 0 },
    failed: { type: Array, default: () => [] },
});

const form = useForm({ file: null });
const upload = () => form.post(route('people.import.preview'), { forceFormData: true });

const confirming = ref(false);
const confirm = () =>
    router.post(route('people.import.confirm', props.token), {}, { onStart: () => (confirming.value = true), onFinish: () => (confirming.value = false) });
</script>

<template>
    <Head title="Import people" />

    <AppLayout>
        <PageHeader title="Import people" subtitle="Invite many people at once from a CSV file.">
            <template #actions>
                <Btn variant="secondary" :href="route('people.index')">Back to people</Btn>
            </template>
        </PageHeader>

        <!-- 1. Upload -->
        <div v-if="stage === 'upload'" class="max-w-2xl space-y-5">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-gray-900">File format</h2>
                <p class="mt-1 text-sm text-gray-600">
                    A CSV file (up to {{ maxRows }} rows) with a header line. <strong>email</strong> and <strong>role</strong> are required; the other columns are optional.
                </p>
                <p class="mt-2 font-mono text-xs text-gray-500">{{ columns.join(', ') }}</p>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600">
                    <li><strong>role</strong> and <strong>department</strong> use the names you have set up in this company.</li>
                    <li><strong>reports_to_email</strong> may be someone already here or someone else in the same file.</li>
                    <li><strong>type</strong> is <em>internal</em> or <em>external</em> and must match the role.</li>
                </ul>
                <a :href="route('people.import.template')" class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline">
                    <ArrowDownTrayIcon class="h-4 w-4" /> Download a template
                </a>
            </div>

            <form class="rounded-xl border border-gray-200 bg-white p-5" @submit.prevent="upload">
                <label class="block text-sm font-medium text-gray-700" for="file">CSV file</label>
                <input
                    id="file"
                    type="file"
                    accept=".csv,text/csv,text/plain"
                    required
                    class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
                    @change="form.file = $event.target.files[0] ?? null"
                />
                <InputError class="mt-1" :message="form.errors.file" />
                <div class="mt-4 flex justify-end"><Btn type="submit" :loading="form.processing">Preview</Btn></div>
            </form>
        </div>

        <!-- 2. Preview -->
        <template v-else-if="stage === 'preview'">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-700">
                    <strong class="text-green-700">{{ validCount }}</strong> ready to invite<template v-if="invalidCount">,
                        <strong class="text-red-700">{{ invalidCount }}</strong> with problems (these are skipped)</template>.
                </p>
                <div class="flex gap-2">
                    <a v-if="invalidCount" :href="route('people.import.errors', token)" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        <ArrowDownTrayIcon class="h-4 w-4" /> Error report
                    </a>
                    <Btn variant="secondary" :href="route('people.import.create')">Upload another file</Btn>
                    <Btn :disabled="!validCount" :loading="confirming" @click="confirm">Invite {{ validCount }} {{ validCount === 1 ? 'person' : 'people' }}</Btn>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2">Line</th><th class="px-3 py-2">Email</th><th class="px-3 py-2">Role</th>
                            <th class="px-3 py-2">Department</th><th class="px-3 py-2">Reports to</th><th class="px-3 py-2">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="r in rows" :key="r.line" :class="r.errors.length ? 'bg-red-50/50' : ''">
                            <td class="px-3 py-2 text-gray-400">{{ r.line }}</td>
                            <td class="px-3 py-2"><div class="font-medium text-gray-900">{{ r.data.email }}</div><div v-if="r.data.name" class="text-xs text-gray-500">{{ r.data.name }}</div></td>
                            <td class="px-3 py-2">{{ r.data.role || '—' }}</td>
                            <td class="px-3 py-2">{{ r.data.department || '—' }}</td>
                            <td class="px-3 py-2">{{ r.data.reports_to_email || (r.data.reports_to_id ? 'existing member' : '—') }}</td>
                            <td class="px-3 py-2">
                                <Badge v-if="!r.errors.length" color="green">Ready</Badge>
                                <ul v-else class="list-disc pl-4 text-xs text-red-700"><li v-for="e in r.errors" :key="e">{{ e }}</li></ul>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- 3. Result -->
        <div v-else class="max-w-3xl space-y-4">
            <div class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 p-4 text-green-800">
                <CheckCircleIcon class="h-6 w-6 shrink-0" />
                <p class="text-sm"><strong>{{ created }}</strong> invitation{{ created === 1 ? '' : 's' }} sent.<template v-if="failed.length"> {{ failed.length }} row{{ failed.length === 1 ? '' : 's' }} could not be imported.</template></p>
            </div>

            <div v-if="failed.length" class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900">Rows that were not imported</h2>
                    <a :href="route('people.import.errors', token)" class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline"><ArrowDownTrayIcon class="h-4 w-4" /> Error report (CSV)</a>
                </div>
                <ul class="divide-y divide-gray-100 text-sm">
                    <li v-for="r in failed" :key="r.line" class="py-2">
                        <span class="text-gray-400">Line {{ r.line }}</span> <span class="font-medium text-gray-900">{{ r.data.email }}</span>
                        <ul class="list-disc pl-5 text-xs text-red-700"><li v-for="e in r.errors" :key="e">{{ e }}</li></ul>
                    </li>
                </ul>
            </div>

            <Link :href="route('people.index', { tab: 'invited' })" class="inline-block text-sm font-medium text-indigo-600 hover:underline">See invited people &rarr;</Link>
        </div>
    </AppLayout>
</template>
