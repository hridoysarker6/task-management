<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import FlashMessages from '@/Components/UI/FlashMessages.vue';
import {
    Bars3Icon,
    BellIcon,
    BriefcaseIcon,
    BuildingOffice2Icon,
    ChartBarIcon,
    ClipboardDocumentCheckIcon,
    HomeIcon,
    UsersIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const sidebarOpen = ref(false);

// Nav items are added phase by phase. `routeName: null` = not built yet (shown disabled).
const nav = [
    { label: 'Dashboard', icon: HomeIcon, routeName: 'dashboard' },
    { label: 'My Work', icon: ClipboardDocumentCheckIcon, routeName: null },
    { label: 'Projects', icon: BriefcaseIcon, routeName: null },
    { label: 'People', icon: UsersIcon, routeName: null },
    { label: 'Reports', icon: ChartBarIcon, routeName: null },
];

const isActive = (item) => item.routeName && route().current(item.routeName + '*');
const linkClass = (item) =>
    item.routeName
        ? isActive(item)
            ? 'bg-indigo-50 text-indigo-700'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
        : 'cursor-not-allowed text-gray-400';
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <FlashMessages />

        <!-- Mobile backdrop -->
        <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-gray-900/40 lg:hidden" @click="sidebarOpen = false" />

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-gray-200 bg-white transition-transform lg:translate-x-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div class="flex h-16 shrink-0 items-center justify-between px-4">
                <Link :href="route('dashboard')" class="flex items-center gap-2">
                    <ApplicationLogo class="h-8 w-8 fill-current text-indigo-600" />
                    <span class="text-base font-semibold text-gray-900">{{ page.props.appName }}</span>
                </Link>
                <button class="rounded-md p-1 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Close menu" @click="sidebarOpen = false">
                    <XMarkIcon class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-2">
                <template v-for="item in nav" :key="item.label">
                    <Link
                        v-if="item.routeName"
                        :href="route(item.routeName)"
                        :class="['flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium', linkClass(item)]"
                        @click="sidebarOpen = false"
                    >
                        <component :is="item.icon" class="h-5 w-5 shrink-0" />
                        {{ item.label }}
                    </Link>
                    <span v-else :class="['flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium', linkClass(item)]" title="Coming soon">
                        <component :is="item.icon" class="h-5 w-5 shrink-0" />
                        {{ item.label }}
                    </span>
                </template>
            </nav>

            <div class="border-t border-gray-200 p-3 text-xs text-gray-400">v0.1 &middot; Phase 0</div>
        </aside>

        <div class="lg:pl-64">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-gray-200 bg-white px-4 sm:px-6">
                <button class="rounded-md p-2 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Open menu" @click="sidebarOpen = true">
                    <Bars3Icon class="h-5 w-5" />
                </button>

                <!-- Company switcher (wired up in Phase 2) -->
                <div class="hidden items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 sm:flex">
                    <BuildingOffice2Icon class="h-4 w-4 text-gray-400" />
                    <span>{{ $page.props.currentOrganization?.name ?? 'No company selected' }}</span>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <!-- Notifications (wired up in Phase 10) -->
                    <button class="relative rounded-full p-2 text-gray-500 hover:bg-gray-100" aria-label="Notifications">
                        <BellIcon class="h-5 w-5" />
                    </button>

                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button type="button" class="flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-gray-100">
                                <Avatar :name="user.name" />
                                <span class="hidden text-sm font-medium text-gray-700 sm:block">{{ user.name }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b border-gray-100 px-4 py-2 text-xs text-gray-500">{{ user.email }}</div>
                            <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">Log Out</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>
    </div>
</template>
