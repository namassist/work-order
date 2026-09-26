<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    History,
    KeyRound,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    SearchX,
    Trash2,
    UserCheck,
    UserX,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import TablePagination from '@/components/admin/TablePagination.vue';
import ClickableRow from '@/components/ClickableRow.vue';
import EmptyState from '@/components/EmptyState.vue';
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
import PersonName from '@/components/PersonName.vue';
import RowActionsMenu from '@/components/RowActionsMenu.vue';
import type { StatItem } from '@/components/StatStrip.vue';
import StatStrip from '@/components/StatStrip.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
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
import { useCan } from '@/composables/useCan';
import { ALL, isFiltering, useListFilters } from '@/composables/useListFilters';
import { panelTableClass } from '@/lib/panel';
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
    /** Non-deleted users by status, ignoring the filters. */
    stats: {
        total: number;
        active: number;
        inactive: number;
        must_change_password: number;
    };
    departments: DepartmentOption[];
    roles: string[];
    can: ListAbilities;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administrasi' },
            { title: 'Pengguna', href: UserController.index() },
        ],
    },
});

const filtered = computed(() => isFiltering(props.filters));

const statItems = computed<StatItem[]>(() => [
    { label: 'Total pengguna', value: props.stats.total, icon: Users },
    { label: 'Aktif', value: props.stats.active, icon: UserCheck },
    { label: 'Nonaktif', value: props.stats.inactive, icon: UserX },
    {
        label: 'Wajib ganti password',
        value: props.stats.must_change_password,
        icon: KeyRound,
    },
]);

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

const hasPermission = useCan();
const page = usePage();

const canDelete = (user: ManagedUser) =>
    props.can.delete && user.id !== page.props.auth.user.id;

const canEdit = (user: ManagedUser) => props.can.update && !user.deleted_at;

const hasActions = (user: ManagedUser) =>
    hasPermission('activity-log.view') ||
    (user.deleted_at ? props.can.restore : canEdit(user) || canDelete(user));
const historyOpen = ref(false);
const historyOf = ref<ManagedUser | null>(null);

const openHistory = (user: ManagedUser) => {
    historyOf.value = user;
    historyOpen.value = true;
};
</script>

<template>
    <Head title="Pengguna" />

    <PagePanel title="Pengguna">
        <StatStrip :items="statItems" />

        <ListToolbar>
            <template v-if="can.create" #actions>
                <Button as-child>
                    <Link :href="UserController.create()">
                        <Plus /> Tambah pengguna
                    </Link>
                </Button>
            </template>

            <div class="relative w-full sm:w-64">
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
                <SelectTrigger
                    class="w-full sm:w-52"
                    aria-label="Filter departemen"
                >
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
                <SelectTrigger class="w-full sm:w-40" aria-label="Filter role">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">Semua role</SelectItem>
                    <SelectItem v-for="role in roles" :key="role" :value="role">
                        {{ role }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select v-model="filters.status">
                <SelectTrigger
                    class="w-full sm:w-40"
                    aria-label="Filter status"
                >
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
        </ListToolbar>

        <Table :class="panelTableClass">
            <TableHeader class="sticky top-0 bg-card">
                <TableRow>
                    <TableHead>Nama</TableHead>
                    <TableHead class="hidden md:table-cell">
                        Departemen
                    </TableHead>
                    <TableHead class="hidden lg:table-cell">Role</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead class="w-0">
                        <span class="sr-only">Aksi</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <ClickableRow
                    v-for="user in users.data"
                    :key="user.id"
                    :disabled="!canEdit(user)"
                    @activate="router.visit(UserController.edit(user.id))"
                >
                    <TableCell class="w-full max-w-0 min-w-48 py-3">
                        <PersonName
                            :name="user.name"
                            :href="
                                canEdit(user)
                                    ? UserController.edit(user.id)
                                    : undefined
                            "
                        >
                            <p class="truncate text-muted-foreground">
                                {{ user.email }}
                            </p>
                        </PersonName>
                    </TableCell>
                    <TableCell class="hidden md:table-cell">
                        <template v-if="user.department">
                            <span class="font-mono">{{
                                user.department.code
                            }}</span>
                            {{ user.department.name }}
                        </template>
                        <span v-else class="text-muted-foreground">—</span>
                    </TableCell>
                    <TableCell
                        class="hidden min-w-56 whitespace-normal lg:table-cell"
                    >
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
                    <TableCell class="text-right">
                        <RowActionsMenu
                            v-if="hasActions(user)"
                            :label="`Aksi ${user.name}`"
                        >
                            <template v-if="user.deleted_at">
                                <DropdownMenuItem
                                    v-if="can.restore"
                                    @select="restore(user)"
                                >
                                    <RotateCcw /> Pulihkan
                                </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem v-else-if="can.update" as-child>
                                <Link :href="UserController.edit(user.id)">
                                    <Pencil /> Ubah
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="hasPermission('activity-log.view')"
                                @select="openHistory(user)"
                            >
                                <History /> Riwayat
                            </DropdownMenuItem>
                            <template
                                v-if="!user.deleted_at && canDelete(user)"
                            >
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    @select="confirmDelete(user)"
                                >
                                    <Trash2 /> Hapus
                                </DropdownMenuItem>
                            </template>
                        </RowActionsMenu>
                    </TableCell>
                </ClickableRow>
                <TableEmpty v-if="users.data.length === 0" :colspan="5">
                    <EmptyState
                        v-if="filtered"
                        :icon="SearchX"
                        title="Tidak ada pengguna yang cocok"
                        description="Ubah kata kunci atau filter pencarian."
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="UserController.index()">
                                Hapus filter
                            </Link>
                        </Button>
                    </EmptyState>
                    <EmptyState
                        v-else
                        :icon="Users"
                        title="Belum ada pengguna"
                        description="Pengguna dibuat oleh admin dengan password default."
                    >
                        <Button v-if="can.create" size="sm" as-child>
                            <Link :href="UserController.create()">
                                <Plus /> Tambah pengguna
                            </Link>
                        </Button>
                    </EmptyState>
                </TableEmpty>
            </TableBody>
        </Table>

        <TablePagination :paginator="users" />
    </PagePanel>

    <ActivityHistorySheet
        v-if="hasPermission('activity-log.view')"
        v-model:open="historyOpen"
        subject-type="user"
        :subject-id="historyOf?.id ?? null"
        :title="historyOf?.name ?? ''"
    />

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Hapus pengguna?"
        :description="`${deleting?.name ?? ''} tidak bisa login lagi dan disembunyikan dari daftar. Riwayat dan role-nya tetap tersimpan, dan admin dapat memulihkannya.`"
        confirm-label="Hapus"
        :processing="processing"
        @confirm="destroy"
    />
</template>
