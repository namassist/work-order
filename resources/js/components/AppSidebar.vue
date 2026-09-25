<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpen,
    Building2,
    FolderGit2,
    LayoutGrid,
    ShieldCheck,
    Tags,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import DepartmentController from '@/actions/App/Http/Controllers/Admin/DepartmentController';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import WorkOrderCategoryController from '@/actions/App/Http/Controllers/Admin/WorkOrderCategoryController';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
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
import { visibleNavItems } from '@/lib/navigation';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const adminNavItems: NavItem[] = [
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
    {
        title: 'Pengguna',
        href: UserController.index(),
        icon: Users,
        permission: 'users.view',
    },
    {
        title: 'Role & Permission',
        href: RoleController.index(),
        icon: ShieldCheck,
        permission: 'roles.manage',
    },
];

const can = useCan();
const visibleAdminNavItems = computed(() =>
    visibleNavItems(adminNavItems, can),
);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
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
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="visibleAdminNavItems.length > 0"
                :items="visibleAdminNavItems"
                label="Administrasi"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
