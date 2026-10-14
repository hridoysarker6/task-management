<script setup>
import InputError from '@/Components/InputError.vue';
import TaskBits from '@/Components/Tasks/TaskBits.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import Badge from '@/Components/UI/Badge.vue';
import Btn from '@/Components/UI/Btn.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import SelectInput from '@/Components/UI/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    requests: { type: Array, required: true },
    assignees: { type: Array, default: () => [] },
    types: { type: Array, required: true },
    priorities: { type: Array, required: true },
});

const typeOptions = computed(() => props.types.filter((t) => t !== 'epic' && t !== 'subtask').map((t) => ({ value: t, label: { story: 'Story', task: 'Task', bug: 'Bug' }[t] })));
const assigneeOptions = computed(() => [{ value: null, label: 'Leave unassigned' }, ...props.assignees.map((a) => ({ value: a.id, label: a.name }))]);

// One small draft per request: what to set when accepting, and the open reject / ask panel.
const drafts = reactive({});
const draft = (r) => (drafts[r.id] ??= { type: r.type === 'epic' ? 'task' : r.type, priority: r.priority, assignee_id: null, mode: null, text: '', error: null, busy: false });

const fmt = (d) => new Date(d).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

const accept = (r) => {
    const d = draft(r);
    d.busy = true;
    router.post(route('tasks.triage.accept', r.key), { type: d.type, priority: d.priority, assignee_id: d.assignee_id }, {
        preserveScroll: true,
        onError: (e) => (d.error = Object.values(e)[0]),
        onFinish: () => (d.busy = false),
    });
};

const submitText = (r) => {
    const d = draft(r);
    d.busy = true;
    d.error = null;
    const url = d.mode === 'reject' ? route('tasks.triage.reject', r.key) : route('tasks.triage.info', r.key);
    router.post(url, d.mode === 'reject' ? { reason: d.text } : { message: d.text }, {
        preserveScroll: true,
        onError: (e) => (d.error = Object.values(e)[0]),
        onSuccess: () => ((d.mode = null), (d.text = '')),
        onFinish: () => (d.busy = false),
    });
};
const expanded = ref(null);
</script>

<template>
    <Head :title="`${project.key} · Triage`" />

    <AppLayout>
        <PageHeader :title="`Triage · ${project.key}`" :subtitle="`Requests waiting for a decision in ${project.name}.`">
            <template #actions><Btn variant="secondary" :href="route('projects.show', project.id)">Back to project</Btn></template>
        </PageHeader>

        <EmptyState v-if="!requests.length" title="The queue is empty" description="New requests from stakeholders appear here until someone accepts or rejects them." />

        <ul v-else class="space-y-4">
            <li v-for="r in requests" :key="r.id" class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex flex-wrap items-start gap-3">
                    <TaskBits kind="type" :value="r.type" />
                    <div class="min-w-0 flex-1">
                        <Link :href="route('tasks.show', r.key)" class="font-medium text-gray-900 hover:text-indigo-600"><span class="mr-2 font-mono text-xs font-semibold text-indigo-700">{{ r.key }}</span>{{ r.title }}</Link>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <Avatar :name="r.reporter" size="sm" /> {{ r.reporter }}<Badge v-if="r.external" color="purple">External</Badge> &middot; {{ fmt(r.created_at) }} &middot; <TaskBits kind="priority" :value="r.priority" />
                        </div>
                    </div>
                    <button class="text-sm font-medium text-indigo-600 hover:underline" @click="expanded = expanded === r.id ? null : r.id">{{ expanded === r.id ? 'Hide details' : 'Show details' }}</button>
                </div>
                <div v-if="expanded === r.id && r.description" class="rich-html mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-800" v-html="r.description" />
                <p v-else-if="expanded === r.id" class="mt-3 text-sm text-gray-400">No description.</p>

                <div class="mt-4 grid items-end gap-3 border-t border-gray-100 pt-4 sm:grid-cols-4">
                    <div><label class="block text-xs font-medium text-gray-500" :for="`t-${r.id}`">Type</label><SelectInput :id="`t-${r.id}`" v-model="draft(r).type" :options="typeOptions" class="mt-1" /></div>
                    <div><label class="block text-xs font-medium text-gray-500" :for="`p-${r.id}`">Priority</label><SelectInput :id="`p-${r.id}`" v-model="draft(r).priority" :options="priorities" class="mt-1" /></div>
                    <div><label class="block text-xs font-medium text-gray-500" :for="`a-${r.id}`">Assign to</label><SelectInput :id="`a-${r.id}`" v-model="draft(r).assignee_id" :options="assigneeOptions" class="mt-1" /></div>
                    <div class="flex gap-2"><Btn :loading="draft(r).busy && !draft(r).mode" @click="accept(r)">Accept</Btn><Btn variant="secondary" @click="draft(r).mode = draft(r).mode === 'info' ? null : 'info'">Ask</Btn><Btn variant="danger" @click="draft(r).mode = draft(r).mode === 'reject' ? null : 'reject'">Reject</Btn></div>
                </div>

                <form v-if="draft(r).mode" class="mt-3 space-y-2" @submit.prevent="submitText(r)">
                    <label class="block text-sm font-medium text-gray-700" :for="`x-${r.id}`">{{ draft(r).mode === 'reject' ? 'Reason (the requester will see this)' : 'Your question for the requester (posted as a public comment)' }}</label>
                    <textarea :id="`x-${r.id}`" v-model="draft(r).text" rows="3" required class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <div class="flex justify-end gap-2"><Btn variant="secondary" size="sm" @click="draft(r).mode = null">Cancel</Btn><Btn type="submit" size="sm" :variant="draft(r).mode === 'reject' ? 'danger' : 'primary'" :loading="draft(r).busy">{{ draft(r).mode === 'reject' ? 'Reject request' : 'Post question' }}</Btn></div>
                </form>
                <InputError class="mt-2" :message="draft(r).error" />
            </li>
        </ul>
    </AppLayout>
</template>
