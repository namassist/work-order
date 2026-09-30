<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    Ban,
    CircleCheckBig,
    CircleDot,
    ClipboardList,
    ClipboardX,
    Download,
    FileCheck,
    FilePen,
    FileSearch,
    History,
    Pencil,
    Plus,
    ReceiptText,
    RotateCcw,
    SearchX,
    Send,
    Stamp,
    Trash2,
    Undo2,
    Wrench,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import WorkOrderExportController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderExportController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import TablePagination from '@/components/admin/TablePagination.vue';
import ClickableRow from '@/components/ClickableRow.vue';
import EmptyState from '@/components/EmptyState.vue';
import PagePanel from '@/components/PagePanel.vue';
import PersonName from '@/components/PersonName.vue';
import RowActionsMenu from '@/components/RowActionsMenu.vue';
import type { StatItem } from '@/components/StatStrip.vue';
import StatStrip from '@/components/StatStrip.vue';
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
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkOrderListFilters from '@/components/work-orders/WorkOrderListFilters.vue';
import WorkOrderStatusBadge from '@/components/work-orders/WorkOrderStatusBadge.vue';
import WorkOrderTitleCell from '@/components/work-orders/WorkOrderTitleCell.vue';
import WorkOrderUrgency from '@/components/work-orders/WorkOrderUrgency.vue';
import { useCan } from '@/composables/useCan';
import { useFormatDate } from '@/composables/useFormatDate';
import { isFiltering, toFilterQuery } from '@/composables/useListFilters';
import { panelTableClass } from '@/lib/panel';
import type {
    WorkOrderFilters,
    WorkOrderStatusGroupOption,
} from '@/lib/workOrderFilters';
import type {
    CategoryOption,
    DepartmentOption,
    Paginated,
    PaymentStatusOption,
    WorkOrderListItem,
    WorkOrderStatusOption,
    WorkOrderUrgencyOption,
} from '@/types';

const props = defineProps<{
    workOrders: Paginated<WorkOrderListItem>;
    filters: WorkOrderFilters;
    statuses: WorkOrderStatusOption[];
    statusGroups: WorkOrderStatusGroupOption[];
    urgencies: WorkOrderUrgencyOption[];
    /** Payment track options (FLOW.md §10), for the Pembayaran filter. */
    paymentStatuses: PaymentStatusOption[];
    /** Visible, non-deleted work orders per status, ignoring the filters. */
    stats: { total: number; statuses: Record<string, number> };
    /** Null unless the user sees every department's work orders. */
    departments: DepartmentOption[] | null;
    /** Executor departments, for the target filter. */
    targetDepartments: DepartmentOption[];
    categories: CategoryOption[];
    can: { create: boolean; restore: boolean; export: boolean };
    /** The most work orders one export may hold. */
    exportMaxRows: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Work Order' },
            { title: 'Daftar WO', href: WorkOrderController.index() },
        ],
    },
});

/** One icon per status; unknown statuses get a plain dot. */
const statusIcons: Record<string, LucideIcon> = {
    draft: FilePen,
    diajukan: Send,
    ditolak: Undo2,
    pelaksanaan: Wrench,
    review_dokumen: FileSearch,
    approval_bast: Stamp,
    bast_disetujui: FileCheck,
    closed: CircleCheckBig,
    dibatalkan: Ban,
};

const statItems = computed<StatItem[]>(() => [
    { label: 'Total WO', value: props.stats.total, icon: ClipboardList },
    ...props.statuses.map((status) => ({
        label: status.label,
        value: props.stats.statuses[status.value] ?? 0,
        icon: statusIcons[status.value] ?? CircleDot,
    })),
]);

/** The sort changes the order, not which work orders match. */
const filtered = computed(() => isFiltering({ ...props.filters, sort: '' }));

/** The export holds exactly the list as the server last filtered it. */
const exportUrl = computed(() =>
    WorkOrderExportController.url({ query: toFilterQuery(props.filters) }),
);

/** The server refuses too large an export too; this saves the round trip. */
const checkExportSize = (event: MouseEvent) => {
    const count = props.workOrders.total;

    if (count > props.exportMaxRows) {
        event.preventDefault();
        toast.error(
            `Hasil filter berisi ${count} work order, melebihi batas ekspor ${props.exportMaxRows}. Persempit filter lalu coba lagi.`,
        );
    }
};
const { formatDateTime, formatCalendarDate } = useFormatDate();
const hasPermission = useCan();

const hasActions = (workOrder: WorkOrderListItem) =>
    hasPermission('activity-log.view') ||
    (workOrder.deleted_at
        ? workOrder.can.restore
        : workOrder.can.update || workOrder.can.delete);

const open = (workOrder: WorkOrderListItem) =>
    router.visit(WorkOrderController.show(workOrder.id));

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

    <PagePanel title="Work Order">
        <StatStrip :items="statItems" />

        <WorkOrderListFilters
            :filters="filters"
            :statuses="statuses"
            :status-groups="statusGroups"
            :urgencies="urgencies"
            :payment-statuses="paymentStatuses"
            :departments="departments"
            :target-departments="targetDepartments"
            :categories="categories"
            :can-restore="can.restore"
        >
            <template v-if="can.create || can.export" #actions>
                <Button v-if="can.create" as-child>
                    <Link :href="WorkOrderController.create()">
                        <Plus /> Buat work order
                    </Link>
                </Button>
                <Button v-if="can.export" variant="outline" as-child>
                    <a :href="exportUrl" @click="checkExportSize">
                        <Download /> Ekspor
                    </a>
                </Button>
            </template>
        </WorkOrderListFilters>

        <Table :class="panelTableClass">
            <TableHeader class="sticky top-0 bg-card">
                <TableRow>
                    <TableHead>Work order</TableHead>
                    <TableHead class="hidden md:table-cell">Pemohon</TableHead>
                    <TableHead class="hidden xl:table-cell">
                        Departemen
                    </TableHead>
                    <TableHead class="hidden xl:table-cell">Kategori</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead class="hidden lg:table-cell">Urgensi</TableHead>
                    <TableHead class="hidden lg:table-cell">Target</TableHead>
                    <TableHead class="hidden lg:table-cell">Dibuat</TableHead>
                    <TableHead class="w-0">
                        <span class="sr-only">Aksi</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <ClickableRow
                    v-for="workOrder in workOrders.data"
                    :key="workOrder.id"
                    :disabled="workOrder.deleted_at !== null"
                    @activate="open(workOrder)"
                >
                    <WorkOrderTitleCell
                        :work-order="workOrder"
                        :linked="workOrder.deleted_at === null"
                    />
                    <TableCell class="hidden md:table-cell">
                        <PersonName :name="workOrder.requester_name" />
                    </TableCell>
                    <TableCell class="hidden font-mono xl:table-cell">
                        {{ workOrder.requester_department.code }}
                    </TableCell>
                    <TableCell class="hidden font-mono xl:table-cell">
                        {{ workOrder.category.code }}
                    </TableCell>
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
                        <span
                            v-if="workOrder.missing_daily_report"
                            class="mt-1 flex items-center gap-1 text-xs font-medium"
                            data-test="missing-daily-report"
                        >
                            <ClipboardX
                                class="size-3.5 text-destructive"
                                aria-hidden="true"
                            />
                            Belum lapor
                        </span>
                    </TableCell>
                    <TableCell class="hidden lg:table-cell">
                        <WorkOrderUrgency :urgency="workOrder.urgency" />
                    </TableCell>
                    <TableCell class="hidden tabular-nums lg:table-cell">
                        {{
                            workOrder.target_date
                                ? formatCalendarDate(workOrder.target_date)
                                : '—'
                        }}
                    </TableCell>
                    <TableCell
                        class="hidden text-muted-foreground tabular-nums lg:table-cell"
                    >
                        {{ formatDateTime(workOrder.created_at) }}
                    </TableCell>
                    <TableCell class="text-right">
                        <RowActionsMenu
                            v-if="hasActions(workOrder)"
                            :label="`Aksi ${workOrder.display_number} ${workOrder.title}`"
                        >
                            <template v-if="workOrder.deleted_at">
                                <DropdownMenuItem
                                    v-if="workOrder.can.restore"
                                    @select="restore(workOrder)"
                                >
                                    <RotateCcw /> Pulihkan
                                </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem
                                v-else-if="workOrder.can.update"
                                as-child
                            >
                                <Link
                                    :href="
                                        WorkOrderController.edit(workOrder.id)
                                    "
                                >
                                    <Pencil /> Ubah
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="hasPermission('activity-log.view')"
                                @select="openHistory(workOrder)"
                            >
                                <History /> Riwayat
                            </DropdownMenuItem>
                            <template
                                v-if="
                                    !workOrder.deleted_at &&
                                    workOrder.can.delete
                                "
                            >
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    @select="confirmDelete(workOrder)"
                                >
                                    <Trash2 /> Hapus
                                </DropdownMenuItem>
                            </template>
                        </RowActionsMenu>
                    </TableCell>
                </ClickableRow>
                <TableEmpty v-if="workOrders.data.length === 0" :colspan="8">
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
    </PagePanel>

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
