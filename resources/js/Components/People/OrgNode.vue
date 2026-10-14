<script setup>
import Avatar from '@/Components/UI/Avatar.vue';
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

// One person in the org chart, with their direct reports nested underneath (collapsible).
const props = defineProps({
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    link: { type: Boolean, default: true },
    openDepth: { type: Number, default: 2 }, // levels expanded by default
});

const open = ref(props.depth < props.openDepth);
</script>

<template>
    <li>
        <div class="flex items-center gap-2 rounded-lg py-1.5 pr-2 hover:bg-gray-50">
            <button
                v-if="node.children.length"
                type="button"
                class="rounded p-0.5 text-gray-400 hover:bg-gray-200 hover:text-gray-700"
                :aria-label="open ? `Collapse ${node.name}` : `Expand ${node.name}`"
                :aria-expanded="open"
                @click="open = !open"
            >
                <component :is="open ? ChevronDownIcon : ChevronRightIcon" class="h-4 w-4" />
            </button>
            <span v-else class="inline-block w-5" />

            <component :is="link ? Link : 'div'" v-bind="link ? { href: route('people.show', node.id) } : {}" class="flex min-w-0 items-center gap-2">
                <Avatar :name="node.name" size="sm" />
                <span class="truncate text-sm font-medium text-gray-900">{{ node.name }}</span>
                <span class="truncate text-xs text-gray-500">{{ node.role }}<template v-if="node.designation"> &middot; {{ node.designation }}</template></span>
            </component>

            <span v-if="node.department" class="hidden rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600 sm:inline">{{ node.department }}</span>
            <span v-if="node.children.length" class="rounded-full bg-indigo-50 px-2 text-[11px] font-medium text-indigo-700" :title="`${node.children.length} direct report(s)`">{{ node.children.length }}</span>
            <span v-if="node.open_tasks !== null" class="rounded-full bg-amber-50 px-2 text-[11px] font-medium text-amber-700" title="Open tasks">{{ node.open_tasks }} open</span>
        </div>

        <ul v-if="node.children.length && open" class="ml-3 border-l border-gray-200 pl-4">
            <OrgNode v-for="c in node.children" :key="c.id" :node="c" :depth="depth + 1" :link="link" :open-depth="openDepth" />
        </ul>
    </li>
</template>
