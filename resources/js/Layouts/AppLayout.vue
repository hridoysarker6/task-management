<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import GlobalSearch from '@/Components/GlobalSearch.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import CreateTaskModal from '@/Components/Tasks/CreateTaskModal.vue';
import Avatar from '@/Components/UI/Avatar.vue';
import FlashMessages from '@/Components/UI/FlashMessages.vue';
import { usePermissions } from '@/Composables/usePermissions';
import {
    ArrowLeftOnRectangleIcon,
    Bars3Icon,
    BellIcon,
    BriefcaseIcon,
    BuildingOffice2Icon,
    BuildingOfficeIcon,
    ChartBarIcon,
    ChevronDoubleLeftIcon,
    ChevronUpDownIcon,
    ClipboardDocumentCheckIcon,
    ClipboardDocumentListIcon,
    ClockIcon,
    Cog6ToothIcon,
    HomeIcon,
    MagnifyingGlassIcon,
    PlusIcon,
    ShareIcon,
    ShieldCheckIcon,
    UserCircleIcon,
    UserGroupIcon,
    UsersIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const company = computed(() => page.props.currentOrganization);
const sidebarOpen = ref(false);
const { can, canAny } = usePermissions();
const organizations = computed(() => page.props.organizations ?? []);
const openCreate = () => window.dispatchEvent(new CustomEvent('create-task'));

// --- collapsible sidebar (remembered per browser) -------------------------------------------------
const collapsed = ref(false);
try {
    collapsed.value = localStorage.getItem('sidebar-collapsed') === '1';
} catch {
    /* storage unavailable: stay expanded */
}
const toggleCollapsed = () => {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem('sidebar-collapsed', collapsed.value ? '1' : '0');
    } catch {
        /* ignore */
    }
};

// --- search and keyboard shortcuts: Ctrl/Cmd+K or / opens search, C creates a task --------------------
const searchOpen = ref(false);
const typing = (el) => el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable);
const onShortcut = (e) => {
    if (!page.props.currentOrganization) return;

    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        searchOpen.value = !searchOpen.value;
        return;
    }
    // Plain keys only count when nobody is typing, no dialog is open and no modifier is held.
    if (e.ctrlKey || e.metaKey || e.altKey || typing(e.target) || document.querySelector('[role="dialog"]')) return;

    if (e.key === '/') {
        e.preventDefault();
        searchOpen.value = true;
    } else if (e.key.toLowerCase() === 'c' && can('task.create')) {
        e.preventDefault();
        openCreate();
    }
};
onMounted(() => window.addEventListener('keydown', onShortcut));
onBeforeUnmount(() => window.removeEventListener('keydown', onShortcut));

// `permission` hides an item from people who lack it; `section` groups items under a small heading.
const allNav = [
    { label: 'Dashboard', icon: HomeIcon, routeName: 'dashboard', section: 'Workspace' },
    { label: 'My Work', icon: ClipboardDocumentCheckIcon, routeName: 'my-work', permission: 'task.view', section: 'Workspace' },
    { label: 'Projects', icon: BriefcaseIcon, routeName: 'projects.index', match: 'projects.*', permission: 'project.view', section: 'Workspace' },
    { label: 'Timesheet', icon: ClockIcon, routeName: 'timesheet', permission: 'worklog.create', section: 'Workspace' },
    { label: 'Team time', icon: ClipboardDocumentListIcon, routeName: 'timesheets.team', permissionAny: ['worklog.view_team', 'timesheet.approve'], section: 'Workspace' },
    { label: 'Team', icon: UserGroupIcon, routeName: 'team', permission: 'dashboard.team', section: 'Organization' },
    { label: 'People', icon: UsersIcon, routeName: 'people.index', match: 'people.*', permission: 'user.view', section: 'Organization' },
    { label: 'Org chart', icon: ShareIcon, routeName: 'orgchart', permission: 'orgchart.view', section: 'Organization' },
    { label: 'Reports', icon: ChartBarIcon, routeName: 'reports.index', match: 'reports.*', permission: 'report.view', section: 'Organization' },
    { label: 'Company', icon: Cog6ToothIcon, routeName: 'settings.company.edit', match: 'settings.company.*', permission: 'company.view_settings', section: 'Settings' },
    { label: 'Departments', icon: BuildingOfficeIcon, routeName: 'departments.index', match: 'departments.*', permission: 'department.view', section: 'Settings' },
    { label: 'Roles & Permissions', icon: ShieldCheckIcon, routeName: 'roles.index', match: 'roles.*', permission: 'role.view', section: 'Settings' },
    { label: 'Audit log', icon: ClipboardDocumentListIcon, routeName: 'audit.index', match: 'audit.*', permission: 'audit.view', section: 'Settings' },
];
const nav = computed(() => allNav.filter((item) => (!item.permission || can(item.permission)) && (!item.permissionAny || canAny(...item.permissionAny))));

// The first visible item of each group carries the group heading.
const sectionStart = computed(() => {
    const seen = new Set();
    return new Set(nav.value.filter((i) => !seen.has(i.section) && seen.add(i.section)).map((i) => i.label));
});

const isActive = (item) => route().current(item.match ?? item.routeName + '*') || (item.routeName === 'dashboard' && route().current('dashboard'));
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <FlashMessages />
        <CreateTaskModal v-if="can('task.create')" />
        <GlobalSearch v-if="company" :show="searchOpen" @close="searchOpen = false" />

        <!-- Mobile backdrop -->
        <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
            <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-gray-900/40 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false" />
        </Transition>

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-40 flex flex-col border-r border-gray-200/80 bg-white transition-all duration-300 ease-out lg:translate-x-0',
                collapsed ? 'lg:w-[4.75rem]' : 'lg:w-64',
                'w-72',
                sidebarOpen ? 'translate-x-0 shadow-xl' : '-translate-x-full',
            ]"
        >
            <div :class="['flex h-16 shrink-0 items-center', collapsed ? 'justify-center px-2' : 'justify-between px-5']">
                <Link :href="route('dashboard')" class="rounded-lg focus-visible:outline-offset-4" aria-label="Task Pro home">
                    <BrandLogo :mark-only="collapsed" />
                </Link>
                <button class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Close menu" @click="sidebarOpen = false"><XMarkIcon class="h-5 w-5" /></button>
            </div>

            <!-- Company chip -->
            <div v-if="company && !collapsed" class="mx-3 mb-2 flex items-center gap-2.5 rounded-xl bg-gray-50 px-3 py-2 ring-1 ring-inset ring-gray-200/70">
                <img v-if="company.logo_url" :src="company.logo_url" :alt="company.name" class="h-7 w-7 rounded-lg object-contain" />
                <span v-else class="flex h-7 w-7 items-center justify-center rounded-lg bg-white text-xs font-bold text-violet-600 shadow-sm ring-1 ring-gray-200">{{ company.name.slice(0, 1) }}</span>
                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800">{{ company.name }}</span>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 pb-3" aria-label="Main">
                <template v-for="item in nav" :key="item.label">
                    <div v-if="sectionStart.has(item.label)" :class="collapsed ? 'mx-2 my-3 border-t border-gray-200 first:hidden' : 'px-3 pb-1.5 pt-5 text-[11px] font-semibold uppercase tracking-wider text-gray-400 first:pt-2'">
                        <span v-if="!collapsed">{{ item.section }}</span>
                    </div>
                    <Link
                        :href="route(item.routeName)"
                        :title="collapsed ? item.label : undefined"
                        :aria-current="isActive(item) ? 'page' : undefined"
                        :class="[
                            'group relative flex items-center gap-3 rounded-lg py-2 text-sm font-medium',
                            collapsed ? 'justify-center px-0' : 'px-3',
                            isActive(item) ? 'bg-violet-50 text-violet-700' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
                        ]"
                        @click="sidebarOpen = false"
                    >
                        <span v-if="isActive(item)" class="absolute -left-3 top-1.5 bottom-1.5 w-1 rounded-r-full bg-violet-600" />
                        <component :is="item.icon" :class="['h-5 w-5 shrink-0 transition-colors', isActive(item) ? 'text-violet-600' : 'text-gray-400 group-hover:text-gray-600']" />
                        <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
                    </Link>
                </template>
            </nav>

            <div class="hidden border-t border-gray-200/80 p-3 lg:block">
                <button type="button" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100 hover:text-gray-800" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" @click="toggleCollapsed">
                    <ChevronDoubleLeftIcon :class="['h-5 w-5 shrink-0 transition-transform duration-300', collapsed ? 'rotate-180' : '']" />
                    <span v-if="!collapsed">Collapse</span>
                </button>
            </div>
        </aside>

        <div :class="['transition-all duration-300 ease-out', collapsed ? 'lg:pl-[4.75rem]' : 'lg:pl-64']">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-gray-200/80 bg-white/80 px-4 backdrop-blur-md sm:px-6">
                <button class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Open menu" @click="sidebarOpen = true"><Bars3Icon class="h-5 w-5" /></button>

                <!-- Search -->
                <button v-if="company" type="button" class="group flex h-10 w-10 shrink-0 items-center justify-center gap-2.5 rounded-xl border border-gray-200 bg-gray-50/70 text-sm text-gray-400 transition hover:border-gray-300 hover:bg-white hover:shadow-sm sm:w-full sm:max-w-md sm:shrink sm:justify-start sm:px-3" aria-label="Search (Ctrl+K)" @click="searchOpen = true">
                    <MagnifyingGlassIcon class="h-4 w-4 shrink-0" />
                    <span class="hidden flex-1 truncate text-left sm:inline">Search tasks, projects, people…</span>
                    <kbd class="hidden rounded-md border border-gray-200 bg-white px-1.5 py-0.5 text-[11px] font-medium text-gray-400 sm:block">Ctrl K</kbd>
                </button>

                <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
                    <!-- Company switcher: a dropdown only for people who belong to more than one company -->
                    <Dropdown v-if="organizations.length > 1" align="right" width="56">
                        <template #trigger>
                            <button type="button" class="flex h-10 items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 text-sm text-gray-700 shadow-sm hover:border-gray-300">
                                <BuildingOffice2Icon class="h-4 w-4 text-gray-400" />
                                <span class="hidden max-w-[9rem] truncate sm:inline">{{ company?.name }}</span>
                                <ChevronUpDownIcon class="h-4 w-4 text-gray-400" />
                            </button>
                        </template>
                        <template #content>
                            <div class="px-4 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Switch company</div>
                            <DropdownLink v-for="o in organizations" :key="o.id" :href="route('organizations.switch', o.id)" method="post" as="button">
                                {{ o.name }}<span v-if="o.id === company?.id" class="ml-1 text-violet-600">&#10003;</span>
                            </DropdownLink>
                        </template>
                    </Dropdown>

                    <button v-if="can('task.create') && company" type="button" class="btn-primary inline-flex h-10 items-center gap-1.5 rounded-xl px-3.5 text-sm font-semibold sm:px-4" @click="openCreate">
                        <PlusIcon class="h-4 w-4" /><span class="hidden sm:inline">Create</span>
                    </button>

                    <NotificationBell v-if="company" />

                    <Dropdown align="right" width="56">
                        <template #trigger>
                            <button type="button" class="flex h-10 items-center gap-2 rounded-xl px-1.5 hover:bg-gray-100 sm:px-2">
                                <Avatar :name="user.name" :src="user.avatar_url" />
                                <span class="hidden text-left sm:block">
                                    <span class="block text-sm font-semibold leading-4 text-gray-800">{{ user.name }}</span>
                                    <span v-if="$page.props.auth.role" class="block text-xs leading-4 text-gray-500">{{ $page.props.auth.role }}</span>
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
                                <DropdownLink v-if="company" :href="route('notifications.preferences')"><span class="flex items-center gap-2"><BellIcon class="h-4 w-4 text-gray-400" /> Notification settings</span></DropdownLink>
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
