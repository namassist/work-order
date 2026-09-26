<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    Ban,
    CircleDot,
    ClipboardList,
    FilePen,
    History,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    SearchX,
    Send,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
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
import WorkOrderStatusBadge from '@/components/work-orders/WorkOrderStatusBadge.vue';
import { useCan } from '@/composables/useCan';
import { useFormatDate } from '@/composables/useFormatDate';
import { ALL, isFiltering, useListFilters } from '@/composables/useListFilters';
import { panelTableClass } from '@/lib/panel';
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
    /** Visible, non-deleted work orders per status, ignoring the filters. */
    stats: { total: number; statuses: Record<string, number> };
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

/** PROVISIONAL like the status flow; unknown statuses get a plain dot. */
const statusIcons: Record<string, LucideIcon> = {
    draft: FilePen,
    diajukan: Send,
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

        <ListToolbar>
            <template v-if="can.create" #actions>
                <Button as-child>
                    <Link :href="WorkOrderController.create()">
                        <Plus /> Buat work order
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
                    placeholder="Cari nomor atau judul"
                    aria-label="Cari work order"
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
                <SelectTrigger
                    class="w-full sm:w-44"
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
            <Select v-model="filters.category">
                <SelectTrigger
                    class="w-full sm:w-44"
                    aria-label="Filter kategori"
                >
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
            <fieldset class="flex w-full min-w-0 items-center gap-2 sm:w-auto">
                <legend class="sr-only">Tanggal dibuat</legend>
                <Label for="filter-from" class="text-muted-foreground">
                    Dibuat
                </Label>
                <Input
                    id="filter-from"
                    v-model="filters.from"
                    type="date"
                    class="min-w-0 flex-1 sm:w-36 sm:flex-none"
                    aria-label="Dibuat dari"
                />
                <span class="text-muted-foreground" aria-hidden="true">–</span>
                <Input
                    id="filter-to"
                    v-model="filters.to"
                    type="date"
                    class="min-w-0 flex-1 sm:w-36 sm:flex-none"
                    aria-label="Dibuat sampai"
                    :min="filters.from || undefined"
                />
            </fieldset>
            <div v-if="can.restore" class="flex h-9 items-center gap-2">
                <Checkbox id="show-trashed" v-model="filters.trashed" />
                <Label for="show-trashed">Tampilkan terhapus</Label>
            </div>
        </ListToolbar>

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
                    <TableCell class="w-full max-w-0 min-w-48 py-3">
                        <component
                            :is="workOrder.deleted_at ? 'span' : Link"
                            v-bind="
                                workOrder.deleted_at
                                    ? {}
                                    : {
                                          href: WorkOrderController.show(
                                              workOrder.id,
                                          ),
                                      }
                            "
                            class="flex min-w-0 items-baseline gap-2 rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground"
                            >
                                {{ workOrder.display_number }}
                            </span>
                            <span class="truncate font-semibold">
                                {{ workOrder.title }}
                            </span>
                        </component>
                        <p
                            v-if="workOrder.description"
                            class="mt-0.5 truncate text-muted-foreground"
                        >
                            {{ workOrder.description }}
                        </p>
                    </TableCell>
                    <TableCell class="hidden md:table-cell">
                        <PersonName :name="workOrder.requester.name" />
                    </TableCell>
                    <TableCell class="hidden font-mono xl:table-cell">
                        {{ workOrder.department.code }}
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
