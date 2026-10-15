<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import FlashMessages from '@/Components/UI/FlashMessages.vue';
import { ArrowLeftOnRectangleIcon, Bars3Icon, BuildingOffice2Icon, ClipboardDocumentListIcon, ExclamationTriangleIcon, HomeIcon, ShieldCheckIcon, UserCircleIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Platform area for the System Admin. Kept separate from AppLayout, which is the company workspace.
const page = usePage();
const user = computed(() => page.props.auth.user);
const sidebarOpen = ref(false);

const nav = [
    { label: 'Dashboard', icon: HomeIcon, routeName: 'admin.dashboard', match: 'admin.dashboard' },
    { label: 'Companies', icon: BuildingOffice2Icon, routeName: 'admin.companies.index', match: 'admin.companies.*' },
    { label: 'Audit log', icon: ClipboardDocumentListIcon, routeName: 'admin.audit.index', match: 'admin.audit.*' },
    { label: 'Failed jobs', icon: ExclamationTriangleIcon, routeName: 'admin.failed-jobs.index', match: 'admin.failed-jobs.*' },
    { label: 'Default roles', icon: ShieldCheckIcon, routeName: 'admin.role-template.index', match: 'admin.role-template.*' },
];

const active = (item) => route().current(item.match);
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <FlashMessages />

        <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
            <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-gray-900/50 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false" />
        </Transition>

        <aside :class="['fixed inset-y-0 left-0 z-40 flex w-72 flex-col bg-gradient-to-b from-slate-900 to-slate-950 transition-transform duration-300 ease-out lg:w-64 lg:translate-x-0', sidebarOpen ? 'translate-x-0 shadow-xl' : '-translate-x-full']">
            <div class="flex h-16 shrink-0 items-center justify-between px-5">
                <Link :href="route('admin.dashboard')" aria-label="Task Pro platform admin"><BrandLogo inverse /></Link>
                <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 lg:hidden" aria-label="Close menu" @click="sidebarOpen = false"><XMarkIcon class="h-5 w-5" /></button>
            </div>
            <div class="px-5 pb-2 pt-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Platform admin</div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-1" aria-label="Main">
                <Link
                    v-for="item in nav"
                    :key="item.label"
                    :href="route(item.routeName)"
                    :aria-current="active(item) ? 'page' : undefined"
                    :class="['group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium', active(item) ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white']"
                    @click="sidebarOpen = false"
                >
                    <span v-if="active(item)" class="absolute -left-3 bottom-1.5 top-1.5 w-1 rounded-r-full bg-violet-400" />
                    <component :is="item.icon" :class="['h-5 w-5 shrink-0', active(item) ? 'text-violet-300' : 'text-slate-500 group-hover:text-slate-300']" />
                    {{ item.label }}
                </Link>
            </nav>
        </aside>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-gray-200/80 bg-white/80 px-4 backdrop-blur-md sm:px-6">
                <button class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Open menu" @click="sidebarOpen = true"><Bars3Icon class="h-5 w-5" /></button>

                <div class="ml-auto">
                    <Dropdown align="right" width="56">
                        <template #trigger>
                            <button type="button" class="flex h-10 items-center gap-2 rounded-xl px-2 hover:bg-gray-100">
                                <Avatar :name="user.name" :src="user.avatar_url" />
                                <span class="hidden text-left sm:block">
                                    <span class="block text-sm font-semibold leading-4 text-gray-800">{{ user.name }}</span>
                                    <span class="block text-xs leading-4 text-gray-500">System admin</span>
                                </span>
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b border-gray-100 px-4 py-2.5">
                                <p class="truncate text-sm font-semibold text-gray-900">{{ user.name }}</p>
                                <p class="truncate text-xs text-gray-500">{{ user.email }}</p>
                            </div>
                            <div class="py-1">
                                <DropdownLink :href="route('profile.edit')"><span class="flex items-center gap-2"><UserCircleIcon class="h-4 w-4 text-gray-400" /> Profile</span></DropdownLink>
                                <DropdownLink :href="route('logout')" method="post" as="button"><span class="flex items-center gap-2"><ArrowLeftOnRectangleIcon class="h-4 w-4 text-gray-400" /> Log out</span></DropdownLink>
                            </div>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <main class="page-enter mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <slot />
            </main>
        </div>
    </div>
</template>
