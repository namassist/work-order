<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building,
    Building2,
    ClipboardList,
    History,
    LayoutGrid,
    ShieldCheck,
    Tags,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import ActivityLogController from '@/actions/App/Http/Controllers/Admin/ActivityLogController';
import CompanyController from '@/actions/App/Http/Controllers/Admin/CompanyController';
import DepartmentController from '@/actions/App/Http/Controllers/Admin/DepartmentController';
import RegistrationController from '@/actions/App/Http/Controllers/Admin/RegistrationController';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import WorkOrderCategoryController from '@/actions/App/Http/Controllers/Admin/WorkOrderCategoryController';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCan } from '@/composables/useCan';
import { visibleNavGroups } from '@/lib/navigation';
import { dashboard } from '@/routes';
import type { NavGroup } from '@/types';

const page = usePage();

/**
 * Sidebar sections in display order. Each item names the permission that
 * shows it (a UI hint only; the server authorizes every request), and a
 * group whose items are all hidden disappears with its heading. Internal
 * groups are never shown to client company (IC) users. Computed, because
 * the Pendaftaran badge follows the shared pending count.
 */
const navGroups = computed<NavGroup[]>(() => [
    {
        items: [{ title: 'Dashboard', href: dashboard(), icon: LayoutGrid }],
    },
    {
        label: 'Work Order',
        items: [
            {
                title: 'Daftar WO',
                href: WorkOrderController.index(),
                icon: ClipboardList,
                permission: 'work-orders.view',
            },
        ],
    },
    {
        label: 'Master Data',
        internal: true,
        items: [
            {
                title: 'Perusahaan',
                href: CompanyController.index(),
                icon: Building,
                permission: 'companies.view',
            },
            {
                title: 'Departemen',
                href: DepartmentController.index(),
                icon: Building2,
                permission: 'departments.view',
            },
            {
                title: 'Kategori WO',
                href: WorkOrderCategoryController.index(),
                icon: Tags,
                permission: 'work-order-categories.view',
            },
        ],
    },
    {
        label: 'Administrasi',
        internal: true,
        items: [
            {
                title: 'Pengguna',
                href: UserController.index(),
                icon: Users,
                permission: 'users.view',
            },
            {
                title: 'Pendaftaran',
                href: RegistrationController.index(),
                icon: UserPlus,
                permission: 'registrations.view',
                badge: page.props.pendingRegistrations,
            },
            {
                title: 'Role & Hak Akses',
                href: RoleController.index(),
                icon: ShieldCheck,
                permission: 'roles.manage',
            },
            {
                title: 'Log Aktivitas',
                href: ActivityLogController.index(),
                icon: History,
                permission: 'activity-log.view',
            },
        ],
    },
]);

const can = useCan();
const visibleGroups = computed(() =>
    visibleNavGroups(navGroups.value, can, page.props.auth.isClient),
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-for="(group, index) in visibleGroups"
                :key="group.label ?? index"
                :items="group.items"
                :label="group.label"
            />
        </SidebarContent>
    </Sidebar>
    <slot />
</template>
