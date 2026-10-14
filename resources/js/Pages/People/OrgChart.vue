<script setup>
import OrgNode from '@/Components/People/OrgNode.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    tree: { type: Array, required: true },
    total: { type: Number, required: true },
    canViewProfiles: { type: Boolean, default: false },
});

// Changing the key re-creates the tree, which resets every node to the chosen depth.
const openDepth = ref(2);
const version = ref(0);
const setDepth = (d) => {
    openDepth.value = d;
    version.value++;
};
</script>

<template>
    <Head title="Org chart" />

    <AppLayout>
        <PageHeader title="Org chart" :subtitle="`${total} ${total === 1 ? 'person' : 'people'} in the reporting hierarchy.`">
            <template #actions>
                <button class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50" @click="setDepth(99)">Expand all</button>
                <button class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50" @click="setDepth(0)">Collapse all</button>
            </template>
        </PageHeader>

        <EmptyState v-if="!tree.length" title="Nobody in the hierarchy yet" description="Invite people and set who they report to; the chart builds itself." />

        <div v-else :key="version" class="rounded-xl border border-gray-200 bg-white p-4">
            <ul class="space-y-0.5">
                <OrgNode v-for="n in tree" :key="n.id" :node="n" :link="canViewProfiles" :open-depth="openDepth" />
            </ul>
        </div>
    </AppLayout>
</template>
