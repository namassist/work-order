<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Lock, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

    <div class="flex flex-1 flex-col gap-4 p-4">
        <PageHeader
            title="Role & Hak Akses"
            description="Atur hak akses tiap role tanpa perlu deploy."
        >
            <template #actions>
                <Button as-child>
                    <Link :href="RoleController.create()">
                        <Plus /> Tambah role
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div class="rounded-2xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Role</TableHead>
                        <TableHead class="text-right">Hak akses</TableHead>
                        <TableHead class="text-right">Pengguna</TableHead>
                        <TableHead class="w-0"
                            ><span class="sr-only">Aksi</span></TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="role in roles" :key="role.id">
                        <TableCell class="font-medium">
                            {{ role.name }}
                            <Badge
                                v-if="role.is_system"
                                variant="outline"
                                class="ml-2"
                            >
                                <Lock /> Sistem
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right tabular-nums">
                            {{ role.permissions_count }}
                        </TableCell>
                        <TableCell class="text-right tabular-nums">
                            {{ role.users_count }}
                        </TableCell>
                        <TableCell class="text-right whitespace-nowrap">
                            <Button variant="ghost" size="icon" as-child>
                                <Link
                                    :href="RoleController.edit(role.id)"
                                    :aria-label="`Ubah ${role.name}`"
                                >
                                    <Pencil />
                                </Link>
                            </Button>
                            <Button
                                v-if="!role.is_system"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Hapus ${role.name}`"
                                @click="confirmDelete(role)"
                            >
                                <Trash2 />
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Hapus role?"
        :description="`Role ${deleting?.name ?? ''} akan dihapus permanen. Role yang masih dipakai pengguna tidak dapat dihapus.`"
        confirm-label="Hapus"
        :processing="processing"
        @confirm="destroy"
    />
</template>
