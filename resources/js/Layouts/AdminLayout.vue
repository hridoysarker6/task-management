<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import FlashMessages from '@/Components/UI/FlashMessages.vue';
import { Bars3Icon, BuildingOffice2Icon, HomeIcon, ShieldCheckIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Platform area for the System Admin. Kept separate from AppLayout, which is the company workspace.
const page = usePage();
const user = computed(() => page.props.auth.user);
const sidebarOpen = ref(false);

const nav = [
    { label: 'Dashboard', icon: HomeIcon, routeName: 'admin.dashboard', match: 'admin.dashboard' },
    { label: 'Companies', icon: BuildingOffice2Icon, routeName: 'admin.companies.index', match: 'admin.companies.*' },
    { label: 'Default roles', icon: ShieldCheckIcon, routeName: 'admin.role-template.index', match: 'admin.role-template.*' },
];

const linkClass = (item) =>
    route().current(item.match) ? 'bg-slate-800 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white';
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <FlashMessages />

        <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-gray-900/40 lg:hidden" @click="sidebarOpen = false" />

        <aside
            :class="[
                'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 transition-transform lg:translate-x-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div class="flex h-16 shrink-0 items-center justify-between px-4">
                <Link :href="route('admin.dashboard')" class="flex items-center gap-2">
                    <ApplicationLogo class="h-8 w-8 fill-current text-indigo-400" />
                    <span class="text-base font-semibold text-white">{{ page.props.appName }}</span>
                </Link>
                <button class="rounded-md p-1 text-slate-400 hover:bg-slate-800 lg:hidden" aria-label="Close menu" @click="sidebarOpen = false">
                    <XMarkIcon class="h-5 w-5" />
                </button>
            </div>
            <div class="px-4 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Platform admin</div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-2">
                <Link
                    v-for="item in nav"
                    :key="item.label"
                    :href="route(item.routeName)"
                    :class="['flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium', linkClass(item)]"
                    @click="sidebarOpen = false"
                >
                    <component :is="item.icon" class="h-5 w-5 shrink-0" />
                    {{ item.label }}
                </Link>
            </nav>
        </aside>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-gray-200 bg-white px-4 sm:px-6">
                <button class="rounded-md p-2 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Open menu" @click="sidebarOpen = true">
                    <Bars3Icon class="h-5 w-5" />
                </button>

                <div class="ml-auto">
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
