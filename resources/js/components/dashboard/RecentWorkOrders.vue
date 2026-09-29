<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { useNow } from '@vueuse/core';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import ClickableRow from '@/components/ClickableRow.vue';
import PersonName from '@/components/PersonName.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import WorkOrderStatusBadge from '@/components/work-orders/WorkOrderStatusBadge.vue';
import WorkOrderTitleCell from '@/components/work-orders/WorkOrderTitleCell.vue';
import WorkOrderUrgency from '@/components/work-orders/WorkOrderUrgency.vue';
import { useFormatDate } from '@/composables/useFormatDate';
import { formatRelative } from '@/lib/format';
import { panelTableClass } from '@/lib/panel';
import type { WorkOrder } from '@/types';

/**
 * Dashboard "WO Terbaru": the work orders the user may see, most recently
 * active first (updated_at, which comments, status changes, and attachments
 * also bump). Rows match the work order list, without its row actions.
 * "Lihat semua" opens the list in the same order.
 */
defineProps<{
    /** Undefined while the deferred prop loads. */
    items?: WorkOrder[];
}>();

const { formatDateTime } = useFormatDate();
const now = useNow({ interval: 60_000 });

const listHref = WorkOrderController.index({ query: { sort: 'diperbarui' } });

const open = (workOrder: WorkOrder) =>
    router.visit(WorkOrderController.show(workOrder.id));
</script>

<template>
    <section aria-labelledby="recent-work-orders-heading" class="border-b">
        <div
            class="flex items-center justify-between gap-2 border-b px-4 py-3 sm:px-6"
        >
            <h2 id="recent-work-orders-heading" class="font-medium">
                WO Terbaru
            </h2>
            <Button variant="ghost" size="sm" as-child>
                <Link :href="listHref" data-test="recent-list-link">
                    Lihat semua <ArrowRight />
                </Link>
            </Button>
        </div>

        <p
            v-if="items?.length === 0"
            class="px-4 py-10 text-center text-sm text-muted-foreground sm:px-6"
            data-test="recent-empty"
        >
            Belum ada work order.
        </p>

        <Table v-else :class="panelTableClass">
            <TableHeader>
                <TableRow>
                    <TableHead>Work order</TableHead>
                    <TableHead class="hidden md:table-cell">Pemohon</TableHead>
                    <TableHead class="hidden xl:table-cell">Kategori</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead class="hidden lg:table-cell">Urgensi</TableHead>
                    <TableHead class="hidden lg:table-cell">
                        Terakhir diperbarui
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <template v-if="items === undefined">
                    <TableRow
                        v-for="n in 4"
                        :key="n"
                        data-test="recent-skeleton"
                    >
                        <TableCell class="py-3">
                            <Skeleton class="h-4 w-48 max-w-full" />
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            <Skeleton class="h-4 w-28" />
                        </TableCell>
                        <TableCell class="hidden xl:table-cell">
                            <Skeleton class="h-4 w-10" />
                        </TableCell>
                        <TableCell>
                            <Skeleton class="h-5 w-20" />
                        </TableCell>
                        <TableCell class="hidden lg:table-cell">
                            <Skeleton class="h-4 w-16" />
                        </TableCell>
                        <TableCell class="hidden lg:table-cell">
                            <Skeleton class="h-4 w-24" />
                        </TableCell>
                    </TableRow>
                </template>
                <template v-else>
                    <ClickableRow
                        v-for="workOrder in items"
                        :key="workOrder.id"
                        @activate="open(workOrder)"
                    >
                        <WorkOrderTitleCell :work-order="workOrder" />
                        <TableCell class="hidden md:table-cell">
                            <PersonName :name="workOrder.requester_name" />
                        </TableCell>
                        <TableCell class="hidden font-mono xl:table-cell">
                            {{ workOrder.category.code }}
                        </TableCell>
                        <TableCell>
                            <WorkOrderStatusBadge :status="workOrder.status" />
                        </TableCell>
                        <TableCell class="hidden lg:table-cell">
                            <WorkOrderUrgency :urgency="workOrder.urgency" />
                        </TableCell>
                        <TableCell
                            class="hidden whitespace-nowrap text-muted-foreground lg:table-cell"
                        >
                            <time
                                :datetime="workOrder.updated_at"
                                :title="formatDateTime(workOrder.updated_at)"
                                data-test="updated-at"
                            >
                                {{ formatRelative(workOrder.updated_at, now) }}
                                <span class="sr-only">
                                    ({{ formatDateTime(workOrder.updated_at) }})
                                </span>
                            </time>
                        </TableCell>
                    </ClickableRow>
                </template>
            </TableBody>
        </Table>
    </section>
</template>
