<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    History,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    SearchX,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DepartmentController from '@/actions/App/Http/Controllers/Admin/DepartmentController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import DepartmentFormDialog from '@/components/admin/DepartmentFormDialog.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import TablePagination from '@/components/admin/TablePagination.vue';
import ClickableRow from '@/components/ClickableRow.vue';
import EmptyState from '@/components/EmptyState.vue';
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
import RowActionsMenu from '@/components/RowActionsMenu.vue';
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
import { isFiltering, useListFilters } from '@/composables/useListFilters';
import { panelTableClass } from '@/lib/panel';
import type { Department, ListAbilities, Paginated } from '@/types';

const props = defineProps<{
    departments: Paginated<Department>;
    filters: { search: string; status: string; trashed: boolean };
    can: ListAbilities;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Master Data' },
            { title: 'Departemen', href: DepartmentController.index() },
        ],
    },
});

const filters = useListFilters(
    { ...props.filters, status: props.filters.status || 'all' },
    () => DepartmentController.index(),
);

const filtered = computed(() => isFiltering(props.filters));

const formOpen = ref(false);
const editing = ref<Department | null>(null);
const deleting = ref<Department | null>(null);
const deleteOpen = ref(false);
const processing = ref(false);

const openCreate = () => {
    editing.value = null;
    formOpen.value = true;
};

const openEdit = (department: Department) => {
    editing.value = department;
    formOpen.value = true;
};

const confirmDelete = (department: Department) => {
    deleting.value = department;
    deleteOpen.value = true;
};

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.visit(DepartmentController.destroy(deleting.value.id), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            deleteOpen.value = false;
        },
    });
};

const restore = (department: Department) => {
    router.visit(DepartmentController.restore(department.id), {
        preserveScroll: true,
    });
};

const hasPermission = useCan();
const historyOpen = ref(false);
const historyOf = ref<Department | null>(null);

const openHistory = (department: Department) => {
    historyOf.value = department;
    historyOpen.value = true;
};

const canEdit = (department: Department) =>
    props.can.update && !department.deleted_at;

const hasActions = (department: Department) =>
    hasPermission('activity-log.view') ||
    (department.deleted_at
        ? props.can.restore
        : props.can.update || props.can.delete);
</script>

<template>
    <Head title="Departemen" />

    <PagePanel title="Departemen">
        <ListToolbar>
            <template v-if="can.create" #actions>
                <Button @click="openCreate">
                    <Plus /> Tambah departemen
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
                    placeholder="Cari kode atau nama"
                    aria-label="Cari departemen"
                />
            </div>
            <Select v-model="filters.status">
                <SelectTrigger
                    class="w-full sm:w-40"
                    aria-label="Filter status"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Semua status</SelectItem>
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
                    <TableHead>Departemen</TableHead>
                    <TableHead class="hidden text-right sm:table-cell"
                        >Pengguna</TableHead
                    >
                    <TableHead>Status</TableHead>
                    <TableHead class="w-0">
                        <span class="sr-only">Aksi</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <ClickableRow
                    v-for="department in departments.data"
                    :key="department.id"
                    :disabled="!canEdit(department)"
                    @activate="openEdit(department)"
                >
                    <TableCell class="w-full max-w-0 min-w-48 py-3">
                        <component
                            :is="canEdit(department) ? 'button' : 'span'"
                            v-bind="
                                canEdit(department) ? { type: 'button' } : {}
                            "
                            class="flex max-w-full min-w-0 items-baseline gap-2 rounded-sm text-left underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="{ 'hover:underline': canEdit(department) }"
                            @click="canEdit(department) && openEdit(department)"
                        >
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground"
                            >
                                {{ department.code }}
                            </span>
                            <span class="truncate font-semibold">
                                {{ department.name }}
                            </span>
                        </component>
                    </TableCell>
                    <TableCell
                        class="hidden text-right tabular-nums sm:table-cell"
                    >
                        {{ department.users_count }}
                    </TableCell>
                    <TableCell>
                        <StatusBadge
                            :is-active="department.is_active"
                            :deleted="department.deleted_at !== null"
                        />
                    </TableCell>
                    <TableCell class="text-right">
                        <RowActionsMenu
                            v-if="hasActions(department)"
                            :label="`Aksi ${department.code}`"
                        >
                            <template v-if="department.deleted_at">
                                <DropdownMenuItem
                                    v-if="can.restore"
                                    @select="restore(department)"
                                >
                                    <RotateCcw /> Pulihkan
                                </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem
                                v-else-if="can.update"
                                @select="openEdit(department)"
                            >
                                <Pencil /> Ubah
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="hasPermission('activity-log.view')"
                                @select="openHistory(department)"
                            >
                                <History /> Riwayat
                            </DropdownMenuItem>
                            <template
                                v-if="!department.deleted_at && can.delete"
                            >
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    @select="confirmDelete(department)"
                                >
                                    <Trash2 /> Hapus
                                </DropdownMenuItem>
                            </template>
                        </RowActionsMenu>
                    </TableCell>
                </ClickableRow>
                <TableEmpty v-if="departments.data.length === 0" :colspan="4">
                    <EmptyState
                        v-if="filtered"
                        :icon="SearchX"
                        title="Tidak ada departemen yang cocok"
                        description="Ubah kata kunci atau filter pencarian."
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="DepartmentController.index()">
                                Hapus filter
                            </Link>
                        </Button>
                    </EmptyState>
                    <EmptyState
                        v-else
                        :icon="Building2"
                        title="Belum ada departemen"
                        description="Departemen dipakai untuk mengelompokkan pengguna dan work order."
                    >
                        <Button v-if="can.create" size="sm" @click="openCreate">
                            <Plus /> Tambah departemen
                        </Button>
                    </EmptyState>
                </TableEmpty>
            </TableBody>
        </Table>

        <TablePagination :paginator="departments" />
    </PagePanel>

    <DepartmentFormDialog v-model:open="formOpen" :department="editing" />

    <ActivityHistorySheet
        v-if="hasPermission('activity-log.view')"
        v-model:open="historyOpen"
        subject-type="department"
        :subject-id="historyOf?.id ?? null"
        :title="historyOf?.code ?? ''"
    />

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Hapus departemen?"
        :description="`Departemen ${deleting?.code ?? ''} akan disembunyikan dari daftar dan pilihan. Departemen yang masih memiliki pengguna tidak dapat dihapus; nonaktifkan saja.`"
        confirm-label="Hapus"
        :processing="processing"
        @confirm="destroy"
    />
</template>
