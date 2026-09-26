<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ClipboardList,
    History,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    SearchX,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import TablePagination from '@/components/admin/TablePagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import WorkOrderStatusBadge from '@/components/work-orders/WorkOrderStatusBadge.vue';
import { useCan } from '@/composables/useCan';
import { useFormatDate } from '@/composables/useFormatDate';
import { ALL, isFiltering, useListFilters } from '@/composables/useListFilters';
import type {
    CategoryOption,
    DepartmentOption,
    Paginated,
    WorkOrderListItem,
    WorkOrderStatusOption,
} from '@/types';

type Filters = {
    search: string;
    status: string;
    department: string;
    category: string;
    from: string;
    to: string;
    trashed: boolean;
};

const props = defineProps<{
    workOrders: Paginated<WorkOrderListItem>;
    filters: Filters;
    statuses: WorkOrderStatusOption[];
    /** Null unless the user sees every department's work orders. */
    departments: DepartmentOption[] | null;
    categories: CategoryOption[];
    can: { create: boolean; restore: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Work Order' },
            { title: 'Daftar WO', href: WorkOrderController.index() },
        ],
    },
});

const filters = useListFilters(
    {
        ...props.filters,
        status: props.filters.status || ALL,
        department: props.filters.department || ALL,
        category: props.filters.category || ALL,
    },
    () => WorkOrderController.index(),
);

const filtered = computed(() => isFiltering(props.filters));
const { formatDateTime, formatCalendarDate } = useFormatDate();
const hasPermission = useCan();

const deleting = ref<WorkOrderListItem | null>(null);
const deleteOpen = ref(false);
const processing = ref(false);

const confirmDelete = (workOrder: WorkOrderListItem) => {
    deleting.value = workOrder;
    deleteOpen.value = true;
};

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.visit(WorkOrderController.destroy(deleting.value.id), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            deleteOpen.value = false;
        },
    });
};

const restore = (workOrder: WorkOrderListItem) => {
    router.visit(WorkOrderController.restore(workOrder.id), {
        preserveScroll: true,
    });
};

const historyOpen = ref(false);
const historyOf = ref<WorkOrderListItem | null>(null);

const openHistory = (workOrder: WorkOrderListItem) => {
    historyOf.value = workOrder;
    historyOpen.value = true;
};
</script>

<template>
    <Head title="Daftar WO" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <PageHeader
            title="Work Order"
            :description="
                departments
                    ? 'Work order dari semua departemen.'
                    : 'Work order departemen Anda.'
            "
        >
            <template #actions>
                <Button v-if="can.create" as-child>
                    <Link :href="WorkOrderController.create()">
                        <Plus /> Buat work order
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div class="rounded-2xl border bg-card">
            <div class="flex flex-wrap items-end gap-3 border-b p-4">
                <div class="relative w-full sm:w-72">
                    <Search
                        class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="filters.search"
                        type="search"
                        class="pl-9"
                        placeholder="Cari nomor atau judul"
                        aria-label="Cari work order"
                    />
                </div>
                <Select v-model="filters.status">
                    <SelectTrigger class="w-40" aria-label="Filter status">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Semua status</SelectItem>
                        <SelectItem
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Select v-if="departments" v-model="filters.department">
                    <SelectTrigger class="w-44" aria-label="Filter departemen">
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
                <Select v-model="filters.category">
                    <SelectTrigger class="w-44" aria-label="Filter kategori">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Semua kategori</SelectItem>
                        <SelectItem
                            v-for="category in categories"
                            :key="category.id"
                            :value="String(category.id)"
                        >
                            <span class="font-mono">{{ category.code }}</span>
                            {{ category.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <div class="grid gap-1">
                    <Label for="filter-from" class="text-xs">Dibuat dari</Label>
                    <Input
                        id="filter-from"
                        v-model="filters.from"
                        type="date"
                        class="w-40"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="filter-to" class="text-xs">Sampai</Label>
                    <Input
                        id="filter-to"
                        v-model="filters.to"
                        type="date"
                        class="w-40"
                        :min="filters.from || undefined"
                    />
                </div>
                <div v-if="can.restore" class="flex h-9 items-center gap-2">
                    <Checkbox id="show-trashed" v-model="filters.trashed" />
                    <Label for="show-trashed">Tampilkan terhapus</Label>
                </div>
            </div>

            <Table>
                <TableHeader class="sticky top-0 bg-card">
                    <TableRow>
                        <TableHead>Nomor</TableHead>
                        <TableHead>Judul</TableHead>
                        <TableHead>Departemen</TableHead>
                        <TableHead>Kategori</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Target</TableHead>
                        <TableHead>Dibuat</TableHead>
                        <TableHead class="w-0"
                            ><span class="sr-only">Aksi</span></TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="workOrder in workOrders.data"
                        :key="workOrder.id"
                    >
                        <TableCell class="font-mono whitespace-nowrap">
                            <Link
                                v-if="!workOrder.deleted_at"
                                :href="WorkOrderController.show(workOrder.id)"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ workOrder.display_number }}
                            </Link>
                            <template v-else>{{
                                workOrder.display_number
                            }}</template>
                        </TableCell>
                        <TableCell
                            class="max-w-xs truncate"
                            :title="workOrder.title"
                        >
                            {{ workOrder.title }}
                        </TableCell>
                        <TableCell class="font-mono">{{
                            workOrder.department.code
                        }}</TableCell>
                        <TableCell class="font-mono">{{
                            workOrder.category.code
                        }}</TableCell>
                        <TableCell>
                            <Badge
                                v-if="workOrder.deleted_at"
                                class="border-transparent bg-destructive/10 text-destructive"
                            >
                                Terhapus
                            </Badge>
                            <WorkOrderStatusBadge
                                v-else
                                :status="workOrder.status"
                            />
                        </TableCell>
                        <TableCell class="whitespace-nowrap tabular-nums">
                            {{
                                workOrder.target_date
                                    ? formatCalendarDate(workOrder.target_date)
                                    : '—'
                            }}
                        </TableCell>
                        <TableCell
                            class="whitespace-nowrap text-muted-foreground tabular-nums"
                        >
                            {{ formatDateTime(workOrder.created_at) }}
                        </TableCell>
                        <TableCell class="text-right whitespace-nowrap">
                            <Button
                                v-if="hasPermission('activity-log.view')"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Riwayat ${workOrder.display_number}`"
                                @click="openHistory(workOrder)"
                            >
                                <History />
                            </Button>
                            <template v-if="workOrder.deleted_at">
                                <Button
                                    v-if="workOrder.can.restore"
                                    variant="ghost"
                                    size="sm"
                                    @click="restore(workOrder)"
                                >
                                    <RotateCcw /> Pulihkan
                                </Button>
                            </template>
                            <template v-else>
                                <Button
                                    v-if="workOrder.can.update"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Ubah ${workOrder.title}`"
                                    as-child
                                >
                                    <Link
                                        :href="
                                            WorkOrderController.edit(
                                                workOrder.id,
                                            )
                                        "
                                    >
                                        <Pencil />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="workOrder.can.delete"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Hapus ${workOrder.title}`"
                                    @click="confirmDelete(workOrder)"
                                >
                                    <Trash2 />
                                </Button>
                            </template>
                        </TableCell>
                    </TableRow>
                    <TableEmpty
                        v-if="workOrders.data.length === 0"
                        :colspan="8"
                    >
                        <EmptyState
                            v-if="filtered"
                            :icon="SearchX"
                            title="Tidak ada work order yang cocok"
                            description="Ubah kata kunci atau filter pencarian."
                        >
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="WorkOrderController.index()">
                                    Hapus filter
                                </Link>
                            </Button>
                        </EmptyState>
                        <EmptyState
                            v-else
                            :icon="ClipboardList"
                            title="Belum ada work order"
                            description="Work order baru tersimpan sebagai draft sampai diajukan."
                        >
                            <Button v-if="can.create" size="sm" as-child>
                                <Link :href="WorkOrderController.create()">
                                    <Plus /> Buat work order
                                </Link>
                            </Button>
                        </EmptyState>
                    </TableEmpty>
                </TableBody>
            </Table>

            <TablePagination :paginator="workOrders" />
        </div>
    </div>

    <ActivityHistorySheet
        v-if="hasPermission('activity-log.view')"
        v-model:open="historyOpen"
        subject-type="work-order"
        :subject-id="historyOf?.id ?? null"
        :title="historyOf?.display_number ?? ''"
    />

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Hapus draft?"
        :description="`Draft &quot;${deleting?.title ?? ''}&quot; akan disembunyikan dari daftar. Pulihkan lewat filter &quot;Tampilkan terhapus&quot; bila diperlukan.`"
        confirm-label="Hapus"
        :processing="processing"
        @confirm="destroy"
    />
</template>
