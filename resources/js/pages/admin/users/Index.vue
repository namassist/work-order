<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, RotateCcw, Search, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import TablePagination from '@/components/admin/TablePagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ALL, useListFilters } from '@/composables/useListFilters';
import type {
    DepartmentOption,
    ListAbilities,
    ManagedUser,
    Paginated,
} from '@/types';

const props = defineProps<{
    users: Paginated<ManagedUser>;
    filters: {
        search: string;
        department: string;
        role: string;
        status: string;
        trashed: boolean;
    };
    departments: DepartmentOption[];
    roles: string[];
    can: ListAbilities;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pengguna', href: UserController.index() }],
    },
});

const filters = useListFilters(
    {
        ...props.filters,
        department: props.filters.department || ALL,
        role: props.filters.role || ALL,
        status: props.filters.status || ALL,
    },
    () => UserController.index(),
);

const deleting = ref<ManagedUser | null>(null);
const deleteOpen = ref(false);
const processing = ref(false);

const confirmDelete = (user: ManagedUser) => {
    deleting.value = user;
    deleteOpen.value = true;
};

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.visit(UserController.destroy(deleting.value.id), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            deleteOpen.value = false;
        },
    });
};

const restore = (user: ManagedUser) => {
    router.visit(UserController.restore(user.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Pengguna" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="font-serif text-3xl">Pengguna</h1>
                <p class="text-sm text-muted-foreground">
                    Kelola akun, departemen, dan role pengguna.
                </p>
            </div>
            <Button v-if="can.create" as-child>
                <Link :href="UserController.create()">
                    <Plus /> Tambah pengguna
                </Link>
            </Button>
        </div>

        <div class="rounded-2xl border bg-card">
            <div class="flex flex-wrap items-center gap-3 border-b p-4">
                <div class="relative w-full sm:w-72">
                    <Search
                        class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="filters.search"
                        type="search"
                        class="pl-9"
                        placeholder="Cari nama atau email"
                        aria-label="Cari pengguna"
                    />
                </div>
                <Select v-model="filters.department">
                    <SelectTrigger class="w-52" aria-label="Filter departemen">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Semua departemen</SelectItem>
                        <SelectItem
                            v-for="department in departments"
                            :key="department.id"
                            :value="String(department.id)"
                        >
                            <span class="font-mono">{{ department.code }}</span>
                            {{ department.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="filters.role">
                    <SelectTrigger class="w-40" aria-label="Filter role">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Semua role</SelectItem>
                        <SelectItem
                            v-for="role in roles"
                            :key="role"
                            :value="role"
                        >
                            {{ role }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="filters.status">
                    <SelectTrigger class="w-40" aria-label="Filter status">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Semua status</SelectItem>
                        <SelectItem value="active">Aktif</SelectItem>
                        <SelectItem value="inactive">Nonaktif</SelectItem>
                    </SelectContent>
                </Select>
                <div v-if="can.restore" class="flex items-center gap-2">
                    <Checkbox id="show-trashed" v-model="filters.trashed" />
                    <Label for="show-trashed">Tampilkan terhapus</Label>
                </div>
            </div>

            <Table>
                <TableHeader class="sticky top-0 bg-card">
                    <TableRow>
                        <TableHead>Nama</TableHead>
                        <TableHead>Departemen</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-0"
                            ><span class="sr-only">Aksi</span></TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell>
                            <div class="font-medium">{{ user.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ user.email }}
                            </div>
                        </TableCell>
                        <TableCell>
                            <template v-if="user.department">
                                <span class="font-mono">{{
                                    user.department.code
                                }}</span>
                                {{ user.department.name }}
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in user.roles"
                                    :key="role"
                                    variant="outline"
                                >
                                    {{ role }}
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell>
                            <StatusBadge
                                :is-active="user.is_active"
                                :deleted="user.deleted_at !== null"
                            />
                        </TableCell>
                        <TableCell class="text-right whitespace-nowrap">
                            <template v-if="user.deleted_at">
                                <Button
                                    v-if="can.restore"
                                    variant="ghost"
                                    size="sm"
                                    @click="restore(user)"
                                >
                                    <RotateCcw /> Pulihkan
                                </Button>
                            </template>
                            <template v-else>
                                <Button
                                    v-if="can.update"
                                    variant="ghost"
                                    size="icon"
                                    as-child
                                >
                                    <Link
                                        :href="UserController.edit(user.id)"
                                        :aria-label="`Ubah ${user.name}`"
                                    >
                                        <Pencil />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="
                                        can.delete &&
                                        user.id !== $page.props.auth.user.id
                                    "
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Hapus ${user.name}`"
                                    @click="confirmDelete(user)"
                                >
                                    <Trash2 />
                                </Button>
                            </template>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="users.data.length === 0" :colspan="5">
                        Tidak ada pengguna yang cocok.
                    </TableEmpty>
                </TableBody>
            </Table>

            <TablePagination :paginator="users" />
        </div>
    </div>

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Hapus pengguna?"
        :description="`${deleting?.name ?? ''} tidak bisa login lagi dan disembunyikan dari daftar. Riwayat dan role-nya tetap tersimpan, dan admin dapat memulihkannya.`"
        confirm-label="Hapus"
        :processing="processing"
        @confirm="destroy"
    />
</template>
