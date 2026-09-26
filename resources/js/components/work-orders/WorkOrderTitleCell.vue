<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import { TableCell } from '@/components/ui/table';
import WorkOrderUrgency from '@/components/work-orders/WorkOrderUrgency.vue';
import { isUrgent } from '@/lib/workOrderUrgency';
import type { WorkOrder } from '@/types';

/**
 * The main cell of a work order table row (docs/DESIGN.md › Tabel): number
 * (or "Draft") and title, then the description. The Urgensi column is
 * hidden below lg, so Mendesak shows here instead. The link is the keyboard
 * route to the work order that its ClickableRow opens on click.
 */
withDefaults(
    defineProps<{
        workOrder: WorkOrder;
        /** False for a row that cannot be opened, e.g. a deleted work order. */
        linked?: boolean;
    }>(),
    { linked: true },
);
</script>

<template>
    <TableCell class="w-full max-w-0 min-w-48 py-3">
        <component
            :is="linked ? Link : 'span'"
            v-bind="
                linked ? { href: WorkOrderController.show(workOrder.id) } : {}
            "
            class="flex min-w-0 items-baseline gap-2 rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <span class="shrink-0 font-mono text-xs text-muted-foreground">
                {{ workOrder.display_number }}
            </span>
            <span class="truncate font-semibold">
                {{ workOrder.title }}
            </span>
        </component>
        <div
            v-if="workOrder.description || isUrgent(workOrder.urgency.value)"
            :class="[
                'mt-0.5 flex min-w-0 items-center gap-2',
                { 'lg:hidden': !workOrder.description },
            ]"
        >
            <WorkOrderUrgency
                v-if="isUrgent(workOrder.urgency.value)"
                :urgency="workOrder.urgency"
                class="shrink-0 text-xs lg:hidden"
            />
            <p
                v-if="workOrder.description"
                class="truncate text-muted-foreground"
            >
                {{ workOrder.description }}
            </p>
        </div>
    </TableCell>
</template>
