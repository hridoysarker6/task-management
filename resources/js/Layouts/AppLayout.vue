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
    BuildingOfficeIcon,
    ChartBarIcon,
    ChevronUpDownIcon,
    ClipboardDocumentCheckIcon,
    Cog6ToothIcon,
    HomeIcon,
    ShareIcon,
    PlusIcon,
    ShieldCheckIcon,
    UserGroupIcon,
    UsersIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import CreateTaskModal from '@/Components/Tasks/CreateTaskModal.vue';
import { usePermissions } from '@/Composables/usePermissions';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const sidebarOpen = ref(false);
const { can } = usePermissions();
const organizations = computed(() => page.props.organizations ?? []);
const openCreate = () => window.dispatchEvent(new CustomEvent('create-task'));

// Nav items are added phase by phase. `routeName: null` = not built yet (shown disabled).
// `permission` hides the item from people who lack it.
const allNav = [
    { label: 'Dashboard', icon: HomeIcon, routeName: 'dashboard' },
    { label: 'My Work', icon: ClipboardDocumentCheckIcon, routeName: 'my-work', permission: 'task.view' },
    { label: 'Projects', icon: BriefcaseIcon, routeName: 'projects.index', match: 'projects.*', permission: 'project.view' },
    { label: 'Team', icon: UserGroupIcon, routeName: 'team', permission: 'dashboard.team' },
    { label: 'People', icon: UsersIcon, routeName: 'people.index', match: 'people.*', permission: 'user.view' },
    { label: 'Org chart', icon: ShareIcon, routeName: 'orgchart', permission: 'orgchart.view' },
    { label: 'Reports', icon: ChartBarIcon, routeName: null },
    { label: 'Company', icon: Cog6ToothIcon, routeName: 'settings.company.edit', match: 'settings.company.*', permission: 'company.view_settings', section: 'Settings' },
    { label: 'Departments', icon: BuildingOfficeIcon, routeName: 'departments.index', match: 'departments.*', permission: 'department.view' },
    { label: 'Roles & Permissions', icon: ShieldCheckIcon, routeName: 'roles.index', match: 'roles.*', permission: 'role.view' },
];
const nav = computed(() => allNav.filter((item) => !item.permission || can(item.permission)));

// The first visible item of the settings group gets a small heading above it.
const sectionStart = computed(() => {
    const seen = new Set();
    return new Set(nav.value.filter((i) => i.section && !seen.has(i.section) && seen.add(i.section)).map((i) => i.label));
});

const isActive = (item) => item.routeName && route().current(item.match ?? item.routeName + '*');
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
        <CreateTaskModal v-if="can('task.create')" />

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
                    <img v-if="page.props.currentOrganization?.logo_url" :src="page.props.currentOrganization.logo_url" :alt="page.props.currentOrganization.name" class="h-8 w-8 rounded object-contain" />
                    <ApplicationLogo v-else class="h-8 w-8 fill-current text-indigo-600" />
                    <span class="truncate text-base font-semibold text-gray-900">{{ page.props.currentOrganization?.name ?? page.props.appName }}</span>
                </Link>
                <button class="rounded-md p-1 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Close menu" @click="sidebarOpen = false">
                    <XMarkIcon class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-2">
                <template v-for="item in nav" :key="item.label">
                    <div v-if="sectionStart.has(item.label)" class="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ item.section }}</div>
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

            <div class="border-t border-gray-200 p-3 text-xs text-gray-400">v0.1 &middot; Phase 5</div>
        </aside>

        <div class="lg:pl-64">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-gray-200 bg-white px-4 sm:px-6">
                <button class="rounded-md p-2 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Open menu" @click="sidebarOpen = true">
                    <Bars3Icon class="h-5 w-5" />
                </button>

                <!-- Company switcher: a dropdown only for people who belong to more than one company -->
                <Dropdown v-if="organizations.length > 1" align="left" width="48">
                    <template #trigger>
                        <button type="button" class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                            <BuildingOffice2Icon class="h-4 w-4 text-gray-400" />
                            <span>{{ $page.props.currentOrganization?.name }}</span>
                            <ChevronUpDownIcon class="h-4 w-4 text-gray-400" />
                        </button>
                    </template>
                    <template #content>
                        <div class="border-b border-gray-100 px-4 py-2 text-xs text-gray-500">Switch company</div>
                        <DropdownLink v-for="o in organizations" :key="o.id" :href="route('organizations.switch', o.id)" method="post" as="button">
                            {{ o.name }}<span v-if="o.id === $page.props.currentOrganization?.id" class="ml-1 text-indigo-600">&#10003;</span>
                        </DropdownLink>
                    </template>
                </Dropdown>
                <div v-else class="hidden items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 sm:flex">
                    <BuildingOffice2Icon class="h-4 w-4 text-gray-400" />
                    <span>{{ $page.props.currentOrganization?.name ?? 'No company selected' }}</span>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <button v-if="can('task.create') && $page.props.currentOrganization" type="button" class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700" @click="openCreate">
                        <PlusIcon class="h-4 w-4" /> Create
                    </button>
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
