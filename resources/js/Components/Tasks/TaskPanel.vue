<script setup>
import TaskDetail from '@/Components/Tasks/TaskDetail.vue';
import Skeleton from '@/Components/UI/Skeleton.vue';
import { closeTask, useTaskPanel } from '@/Composables/useTaskPanel';
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { ArrowTopRightOnSquareIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * The task side panel: the full task, in a slide-over, without leaving the board or list. Everything inside is the
 * same TaskDetail the task page uses; after any change made inside it, the task is fetched again so it stays current.
 * Mounted once by AppLayout. The task is also in the address (?task=ECP-12), so a refresh or a shared link reopens it.
 */
const { state } = useTaskPanel();

const payload = ref(null);
const loading = ref(false);
const failed = ref('');
const version = ref(0); // changing it re-creates TaskDetail so its local copies match the fresh data
let request = 0;

const load = async (key, quiet = false) => {
    const mine = ++request;
    if (!quiet) {
        loading.value = true;
        payload.value = null;
    }
    failed.value = '';

    try {
        const { data } = await axios.get(route('tasks.show', key), { params: { panel: 1 }, headers: { Accept: 'application/json' } });
        if (mine !== request) return;
        payload.value = data;
        version.value++;
    } catch (e) {
        if (mine !== request) return;
        failed.value = [403, 404].includes(e.response?.status) ? 'You cannot open this task. It may have been deleted, or it is outside your access.' : 'The task could not be loaded. Please try again.';
    } finally {
        if (mine === request) loading.value = false;
    }
};

watch(
    () => state.key,
    (key) => {
        if (key) load(key);
        else {
            request++;
            payload.value = null;
        }
    },
);

const offs = [];
onMounted(() => {
    state.host = true;

    // Anything saved while the panel is open (a status, a comment, a log) refreshes the panel's copy.
    offs.push(router.on('success', () => state.key && load(state.key, true)));
    // Going to another page closes the panel; a reload of the same page keeps it.
    offs.push(
        router.on('navigate', (event) => {
            if (state.key && new URL(event.detail.page.url, window.location.origin).pathname !== state.openedPath) state.key = null;
        }),
    );

    // A link with ?task=KEY opens straight into the panel.
    const wanted = new URLSearchParams(window.location.search).get('task');
    if (wanted) {
        state.openedPath = window.location.pathname;
        state.key = wanted;
    }
});
onBeforeUnmount(() => {
    state.host = false;
    offs.forEach((off) => off());
});
</script>

<template>
    <TransitionRoot as="template" :show="!!state.key">
        <Dialog class="relative z-40" @close="closeTask">
            <TransitionChild as="template" enter="ease-out duration-200" enter-from="opacity-0" enter-to="opacity-100" leave="ease-in duration-150" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-gray-900/30 backdrop-blur-[1px]" aria-hidden="true" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-hidden">
                <div class="pointer-events-none absolute inset-y-0 right-0 flex max-w-full">
                    <TransitionChild as="template" enter="transform transition ease-out duration-300" enter-from="translate-x-full" enter-to="translate-x-0" leave="transform transition ease-in duration-200" leave-from="translate-x-0" leave-to="translate-x-full">
                        <DialogPanel class="pointer-events-auto flex h-full w-screen max-w-3xl flex-col bg-gray-50 shadow-xl ring-1 ring-gray-900/10 sm:rounded-l-2xl">
                            <header class="flex shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-5 py-3 sm:rounded-tl-2xl">
                                <span class="font-mono text-sm font-semibold text-violet-700">{{ state.key }}</span>
                                <a :href="state.key ? route('tasks.show', state.key) : '#'" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-violet-700">
                                    <ArrowTopRightOnSquareIcon class="h-4 w-4" /> Open full page
                                </a>
                                <button type="button" class="ml-auto rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800" aria-label="Close task panel" @click="closeTask"><XMarkIcon class="h-5 w-5" /></button>
                            </header>

                            <div class="min-h-0 flex-1 overflow-y-auto p-5">
                                <div v-if="loading" class="space-y-4" aria-busy="true"><Skeleton :lines="2" /><Skeleton :lines="6" /></div>
                                <p v-else-if="failed" role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-inset ring-rose-600/20">{{ failed }}</p>
                                <TaskDetail v-else-if="payload" :key="version" v-bind="payload" in-panel />
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
