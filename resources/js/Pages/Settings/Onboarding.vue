<script setup>
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { CheckCircleIcon } from '@heroicons/vue/24/solid';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    steps: { type: Array, required: true }, // [{ key, title, text, href, status: todo|done|skipped }]
    status: { type: String, default: null },
});

const doneCount = computed(() => props.steps.filter((s) => s.status !== 'todo').length);

const mark = (step, status) => router.post(route('onboarding.update'), { step: step.key, status }, { preserveScroll: true });
const finish = () => router.post(route('onboarding.update'), { finish: true });
</script>

<template>
    <Head title="Set up your company" />

    <AppLayout>
        <PageHeader title="Set up your company" subtitle="Six quick steps. Skip any of them and come back later from Settings.">
            <template #actions>
                <Btn variant="secondary" @click="finish">Skip setup</Btn>
            </template>
        </PageHeader>

        <div class="mb-4 h-2 max-w-2xl overflow-hidden rounded-full bg-gray-200" role="progressbar" :aria-valuenow="doneCount" aria-valuemin="0" :aria-valuemax="steps.length">
            <div class="h-full bg-indigo-600 transition-all" :style="{ width: `${(doneCount / steps.length) * 100}%` }" />
        </div>

        <ol class="max-w-2xl space-y-3">
            <li v-for="(s, i) in steps" :key="s.key" class="flex items-start gap-4 rounded-xl border border-gray-200 bg-white p-4">
                <span v-if="s.status === 'done'" class="mt-0.5 text-green-600"><CheckCircleIcon class="h-6 w-6" /></span>
                <span v-else class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-gray-300 text-xs font-semibold text-gray-500">{{ i + 1 }}</span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-sm font-semibold text-gray-900">{{ s.title }}</h2>
                        <Badge v-if="s.status === 'skipped'" color="gray">Skipped</Badge>
                        <Badge v-else-if="s.status === 'done'" color="green">Done</Badge>
                    </div>
                    <p class="mt-0.5 text-sm text-gray-600">{{ s.text }}</p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <Btn v-if="s.href" size="sm" :variant="s.status === 'done' ? 'secondary' : 'primary'" :href="s.href">{{ s.status === 'done' ? 'Open again' : 'Open' }}</Btn>
                        <Badge v-else color="gray">Coming soon</Badge>
                        <button v-if="s.status !== 'done'" class="text-xs text-gray-500 underline hover:text-gray-800" @click="mark(s, 'done')">Mark done</button>
                        <button v-if="s.status === 'todo'" class="text-xs text-gray-500 underline hover:text-gray-800" @click="mark(s, 'skipped')">Skip</button>
                        <button v-if="s.status !== 'todo'" class="text-xs text-gray-500 underline hover:text-gray-800" @click="mark(s, 'todo')">Reset</button>
                    </div>
                </div>
            </li>
        </ol>

        <div class="mt-6 max-w-2xl">
            <Btn @click="finish">{{ doneCount === steps.length ? 'Finish' : 'Finish later' }}</Btn>
            <Link v-if="status === 'done'" :href="route('dashboard')" class="ml-3 text-sm text-gray-600 underline">Back to dashboard</Link>
        </div>
    </AppLayout>
</template>
