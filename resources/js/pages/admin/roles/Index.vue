<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Lock, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import ClickableRow from '@/components/ClickableRow.vue';
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
import RowActionsMenu from '@/components/RowActionsMenu.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { panelTableClass } from '@/lib/panel';
import type { RoleSummary } from '@/types';

defineProps<{
    roles: RoleSummary[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administrasi' },
            { title: 'Role & Hak Akses', href: RoleController.index() },
        ],
    },
});

const deleting = ref<RoleSummary | null>(null);
const deleteOpen = ref(false);
const processing = ref(false);

const confirmDelete = (role: RoleSummary) => {
    deleting.value = role;
    deleteOpen.value = true;
};

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.visit(RoleController.destroy(deleting.value.id), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            deleteOpen.value = false;
        },
    });
};
</script>

<template>
    <Head title="Role & Hak Akses" />

    <PagePanel title="Role & Hak Akses">
        <ListToolbar>
            <template #actions>
                <Button as-child>
                    <Link :href="RoleController.create()">
                        <Plus /> Tambah role
                    </Link>
                </Button>
            </template>
        </ListToolbar>

        <Table :class="panelTableClass">
            <TableHeader>
                <TableRow>
                    <TableHead>Role</TableHead>
                    <TableHead class="text-right">Hak akses</TableHead>
                    <TableHead class="text-right">Pengguna</TableHead>
                    <TableHead class="w-0">
                        <span class="sr-only">Aksi</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <ClickableRow
                    v-for="role in roles"
                    :key="role.id"
                    @activate="router.visit(RoleController.edit(role.id))"
                >
                    <TableCell class="w-full py-3">
                        <div class="flex items-center gap-2">
                            <Link
                                :href="RoleController.edit(role.id)"
                                class="rounded-sm font-mono font-semibold underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {{ role.name }}
                            </Link>
                            <Badge v-if="role.is_system" variant="outline">
                                <Lock /> Sistem
                            </Badge>
                        </div>
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                        {{ role.permissions_count }}
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                        {{ role.users_count }}
                    </TableCell>
                    <TableCell class="text-right">
                        <RowActionsMenu :label="`Aksi ${role.name}`">
                            <DropdownMenuItem as-child>
                                <Link :href="RoleController.edit(role.id)">
                                    <Pencil /> Ubah
                                </Link>
                            </DropdownMenuItem>
                            <template v-if="!role.is_system">
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    @select="confirmDelete(role)"
                                >
                                    <Trash2 /> Hapus
                                </DropdownMenuItem>
                            </template>
                        </RowActionsMenu>
                    </TableCell>
                </ClickableRow>
            </TableBody>
        </Table>
    </PagePanel>

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Hapus role?"
        :description="`Role ${deleting?.name ?? ''} akan dihapus permanen. Role yang masih dipakai pengguna tidak dapat dihapus.`"
        confirm-label="Hapus"
        :processing="processing"
        @confirm="destroy"
    />
</template>
