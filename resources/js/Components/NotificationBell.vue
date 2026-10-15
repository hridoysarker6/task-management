<script setup>
import { BellIcon, Cog6ToothIcon } from '@heroicons/vue/24/outline';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// The bell in the top bar: unread count, the latest few, mark read on click. Refreshes every minute.
const open = ref(false);
const unread = ref(0);
const items = ref([]);
const root = ref(null);
let timer;

const load = async () => {
    try {
        const { data } = await axios.get(route('notifications.recent'));
        unread.value = data.unread;
        items.value = data.items;
    } catch {
        /* the bell is a convenience: stay quiet when the request fails */
    }
};

const ago = (iso) => {
    const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 1) return 'just now';
    if (m < 60) return `${m} min ago`;
    if (m < 1440) return `${Math.round(m / 60)} h ago`;
    return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
};

const go = async (n) => {
    open.value = false;
    if (!n.read) {
        try {
            await axios.post(route('notifications.read', n.id));
        } catch {
            /* still go there */
        }
        n.read = true;
        unread.value = Math.max(0, unread.value - 1);
    }
    if (n.url) router.visit(n.url);
};

const readAll = async () => {
    await axios.post(route('notifications.read-all'), {}, { headers: { Accept: 'application/json' } });
    items.value.forEach((n) => (n.read = true));
    unread.value = 0;
};

const outside = (e) => {
    if (root.value && !root.value.contains(e.target)) open.value = false;
};

onMounted(() => {
    load();
    timer = setInterval(load, 60000);
    document.addEventListener('click', outside);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('click', outside);
});

const toggle = () => {
    open.value = !open.value;
    if (open.value) load();
};
</script>

<template>
    <div ref="root" class="relative" @keydown.escape="open = false">
        <button type="button" class="relative rounded-full p-2 text-gray-500 hover:bg-gray-100" :aria-label="unread ? `Notifications, ${unread} unread` : 'Notifications'" :aria-expanded="open" @click="toggle">
            <BellIcon class="h-5 w-5" />
            <span v-if="unread" class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white">{{ unread > 99 ? '99+' : unread }}</span>
        </button>

        <div v-if="open" class="absolute right-0 z-40 mt-2 w-80 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg sm:w-96">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                <h2 class="text-sm font-semibold text-gray-900">Notifications</h2>
                <div class="flex items-center gap-3">
                    <button v-if="unread" type="button" class="text-xs text-indigo-600 hover:underline" @click="readAll">Mark all read</button>
                    <Link :href="route('notifications.preferences')" class="text-gray-400 hover:text-gray-700" aria-label="Notification settings" @click="open = false"><Cog6ToothIcon class="h-4 w-4" /></Link>
                </div>
            </div>

            <p v-if="!items.length" class="px-4 py-8 text-center text-sm text-gray-400">You are all caught up.</p>
            <ul v-else class="max-h-96 divide-y divide-gray-100 overflow-y-auto">
                <li v-for="n in items" :key="n.id">
                    <button type="button" class="flex w-full items-start gap-2 px-4 py-2.5 text-left hover:bg-gray-50" @click="go(n)">
                        <span :class="['mt-1.5 h-2 w-2 shrink-0 rounded-full', n.read ? 'bg-transparent' : 'bg-indigo-600']" />
                        <span class="min-w-0 flex-1">
                            <span :class="['block text-sm', n.read ? 'text-gray-600' : 'font-medium text-gray-900']">{{ n.title }}</span>
                            <span v-if="n.body" class="line-clamp-2 block text-xs text-gray-500">{{ n.body }}</span>
                            <span class="block text-xs text-gray-400">{{ ago(n.created_at) }}</span>
                        </span>
                    </button>
                </li>
            </ul>

            <Link :href="route('notifications.index')" class="block border-t border-gray-100 px-4 py-2 text-center text-sm text-indigo-600 hover:bg-gray-50" @click="open = false">See all</Link>
        </div>
    </div>
</template>
