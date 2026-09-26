<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    History,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    SearchX,
    Tags,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import WorkOrderCategoryController from '@/actions/App/Http/Controllers/Admin/WorkOrderCategoryController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import TablePagination from '@/components/admin/TablePagination.vue';
import ClickableRow from '@/components/ClickableRow.vue';
import EmptyState from '@/components/EmptyState.vue';
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
import RowActionsMenu from '@/components/RowActionsMenu.vue';
import WorkOrderCategoryFormDialog from '@/components/admin/WorkOrderCategoryFormDialog.vue';
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
import type { ListAbilities, Paginated, WorkOrderCategory } from '@/types';

const props = defineProps<{
    categories: Paginated<WorkOrderCategory>;
    filters: { search: string; status: string; trashed: boolean };
    can: ListAbilities;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Master Data' },
            {
                title: 'Kategori WO',
                href: WorkOrderCategoryController.index(),
            },
        ],
    },
});

const filters = useListFilters(
    { ...props.filters, status: props.filters.status || 'all' },
    () => WorkOrderCategoryController.index(),
);

const filtered = computed(() => isFiltering(props.filters));

const formOpen = ref(false);
const editing = ref<WorkOrderCategory | null>(null);
const deleting = ref<WorkOrderCategory | null>(null);
const deleteOpen = ref(false);
const processing = ref(false);

const openCreate = () => {
    editing.value = null;
    formOpen.value = true;
};

const openEdit = (category: WorkOrderCategory) => {
    editing.value = category;
    formOpen.value = true;
};

const confirmDelete = (category: WorkOrderCategory) => {
    deleting.value = category;
    deleteOpen.value = true;
};

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.visit(WorkOrderCategoryController.destroy(deleting.value.id), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            deleteOpen.value = false;
        },
    });
};

const restore = (category: WorkOrderCategory) => {
    router.visit(WorkOrderCategoryController.restore(category.id), {
        preserveScroll: true,
    });
};

const hasPermission = useCan();
const historyOpen = ref(false);
const historyOf = ref<WorkOrderCategory | null>(null);

const openHistory = (category: WorkOrderCategory) => {
    historyOf.value = category;
    historyOpen.value = true;
};

const canEdit = (category: WorkOrderCategory) =>
    props.can.update && !category.deleted_at;

const hasActions = (category: WorkOrderCategory) =>
    hasPermission('activity-log.view') ||
    (category.deleted_at
        ? props.can.restore
        : props.can.update || props.can.delete);
</script>

<template>
    <Head title="Kategori WO" />

    <PagePanel title="Kategori Work Order">
        <ListToolbar>
            <template v-if="can.create" #actions>
                <Button @click="openCreate"> <Plus /> Tambah kategori </Button>
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
                    aria-label="Cari kategori"
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
                    <TableHead>Kategori</TableHead>

                    <TableHead>Status</TableHead>
                    <TableHead class="w-0">
                        <span class="sr-only">Aksi</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <ClickableRow
                    v-for="category in categories.data"
                    :key="category.id"
                    :disabled="!canEdit(category)"
                    @activate="openEdit(category)"
                >
                    <TableCell class="w-full max-w-0 min-w-48 py-3">
                        <component
                            :is="canEdit(category) ? 'button' : 'span'"
                            v-bind="canEdit(category) ? { type: 'button' } : {}"
                            class="flex max-w-full min-w-0 items-baseline gap-2 rounded-sm text-left underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="{ 'hover:underline': canEdit(category) }"
                            @click="canEdit(category) && openEdit(category)"
                        >
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground"
                            >
                                {{ category.code }}
                            </span>
                            <span class="truncate font-semibold">
                                {{ category.name }}
                            </span>
                        </component>
                        <p
                            v-if="category.description"
                            class="mt-0.5 truncate text-muted-foreground"
                        >
                            {{ category.description }}
                        </p>
                    </TableCell>

                    <TableCell>
                        <StatusBadge
                            :is-active="category.is_active"
                            :deleted="category.deleted_at !== null"
                        />
                    </TableCell>
                    <TableCell class="text-right">
                        <RowActionsMenu
                            v-if="hasActions(category)"
                            :label="`Aksi ${category.code}`"
                        >
                            <template v-if="category.deleted_at">
                                <DropdownMenuItem
                                    v-if="can.restore"
                                    @select="restore(category)"
                                >
                                    <RotateCcw /> Pulihkan
                                </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem
                                v-else-if="can.update"
                                @select="openEdit(category)"
                            >
                                <Pencil /> Ubah
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="hasPermission('activity-log.view')"
                                @select="openHistory(category)"
                            >
                                <History /> Riwayat
                            </DropdownMenuItem>
                            <template v-if="!category.deleted_at && can.delete">
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    @select="confirmDelete(category)"
                                >
                                    <Trash2 /> Hapus
                                </DropdownMenuItem>
                            </template>
                        </RowActionsMenu>
                    </TableCell>
                </ClickableRow>
                <TableEmpty v-if="categories.data.length === 0" :colspan="3">
                    <EmptyState
                        v-if="filtered"
                        :icon="SearchX"
                        title="Tidak ada kategori yang cocok"
                        description="Ubah kata kunci atau filter pencarian."
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="WorkOrderCategoryController.index()">
                                Hapus filter
                            </Link>
                        </Button>
                    </EmptyState>
                    <EmptyState
                        v-else
                        :icon="Tags"
                        title="Belum ada kategori"
                        description="Kategori menentukan jenis pekerjaan pada work order."
                    >
                        <Button v-if="can.create" size="sm" @click="openCreate">
                            <Plus /> Tambah kategori
                        </Button>
                    </EmptyState>
                </TableEmpty>
            </TableBody>
        </Table>

        <TablePagination :paginator="categories" />
    </PagePanel>

    <WorkOrderCategoryFormDialog v-model:open="formOpen" :category="editing" />

    <ActivityHistorySheet
        v-if="hasPermission('activity-log.view')"
        v-model:open="historyOpen"
        subject-type="wo-category"
        :subject-id="historyOf?.id ?? null"
        :title="historyOf?.code ?? ''"
    />

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Hapus kategori?"
        :description="`Kategori ${deleting?.code ?? ''} akan disembunyikan dari daftar dan pilihan. Pulihkan lewat filter &quot;Tampilkan terhapus&quot; bila diperlukan.`"
        confirm-label="Hapus"
        :processing="processing"
        @confirm="destroy"
    />
</template>
