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
import WorkOrderCategoryFormDialog from '@/components/admin/WorkOrderCategoryFormDialog.vue';
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
import { useCan } from '@/composables/useCan';
import { isFiltering, useListFilters } from '@/composables/useListFilters';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
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
</script>

<template>
    <Head title="Kategori WO" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <PageHeader
            title="Kategori Work Order"
            description='Kategori nonaktif tetap tampil; yang dihapus hanya terlihat lewat filter "Tampilkan terhapus".'
        >
            <template #actions>
                <Button v-if="can.create" @click="openCreate">
                    <Plus /> Tambah kategori
                </Button>
            </template>
        </PageHeader>

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
                        placeholder="Cari kode atau nama"
                        aria-label="Cari kategori"
                    />
                </div>
                <Select v-model="filters.status">
                    <SelectTrigger class="w-40" aria-label="Filter status">
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
            </div>

            <Table>
                <TableHeader class="sticky top-0 bg-card">
                    <TableRow>
                        <TableHead>Kode</TableHead>
                        <TableHead>Nama</TableHead>
                        <TableHead>Deskripsi</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-0"
                            ><span class="sr-only">Aksi</span></TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="category in categories.data"
                        :key="category.id"
                    >
                        <TableCell class="font-mono">{{
                            category.code
                        }}</TableCell>
                        <TableCell>{{ category.name }}</TableCell>
                        <TableCell
                            class="max-w-xs truncate text-muted-foreground"
                            :title="category.description ?? undefined"
                        >
                            {{ category.description ?? '—' }}
                        </TableCell>
                        <TableCell>
                            <StatusBadge
                                :is-active="category.is_active"
                                :deleted="category.deleted_at !== null"
                            />
                        </TableCell>
                        <TableCell class="text-right whitespace-nowrap">
                            <Button
                                v-if="hasPermission('activity-log.view')"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Riwayat ${category.code}`"
                                @click="openHistory(category)"
                            >
                                <History />
                            </Button>
                            <template v-if="category.deleted_at">
                                <Button
                                    v-if="can.restore"
                                    variant="ghost"
                                    size="sm"
                                    @click="restore(category)"
                                >
                                    <RotateCcw /> Pulihkan
                                </Button>
                            </template>
                            <template v-else>
                                <Button
                                    v-if="can.update"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Ubah ${category.code}`"
                                    @click="openEdit(category)"
                                >
                                    <Pencil />
                                </Button>
                                <Button
                                    v-if="can.delete"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Hapus ${category.code}`"
                                    @click="confirmDelete(category)"
                                >
                                    <Trash2 />
                                </Button>
                            </template>
                        </TableCell>
                    </TableRow>
                    <TableEmpty
                        v-if="categories.data.length === 0"
                        :colspan="5"
                    >
                        <EmptyState
                            v-if="filtered"
                            :icon="SearchX"
                            title="Tidak ada kategori yang cocok"
                            description="Ubah kata kunci atau filter pencarian."
                        >
                            <Button variant="outline" size="sm" as-child>
                                <Link
                                    :href="WorkOrderCategoryController.index()"
                                >
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
                            <Button
                                v-if="can.create"
                                size="sm"
                                @click="openCreate"
                            >
                                <Plus /> Tambah kategori
                            </Button>
                        </EmptyState>
                    </TableEmpty>
                </TableBody>
            </Table>

            <TablePagination :paginator="categories" />
        </div>
    </div>

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
