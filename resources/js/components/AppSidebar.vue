<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Building2,
    ClipboardList,
    History,
    LayoutGrid,
    ShieldCheck,
    Tags,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import ActivityLogController from '@/actions/App/Http/Controllers/Admin/ActivityLogController';
import DepartmentController from '@/actions/App/Http/Controllers/Admin/DepartmentController';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import WorkOrderCategoryController from '@/actions/App/Http/Controllers/Admin/WorkOrderCategoryController';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCan } from '@/composables/useCan';
import { visibleNavGroups } from '@/lib/navigation';
import { dashboard } from '@/routes';
import type { NavGroup } from '@/types';

/**
 * Sidebar sections in display order. Each item names the permission that
 * shows it (a UI hint only; the server authorizes every request), and a
 * group whose items are all hidden disappears with its heading.
 */
const navGroups: NavGroup[] = [
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
        items: [
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
        items: [
            {
                title: 'Pengguna',
                href: UserController.index(),
                icon: Users,
                permission: 'users.view',
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
];

const can = useCan();
const visibleGroups = computed(() => visibleNavGroups(navGroups, can));
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

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
