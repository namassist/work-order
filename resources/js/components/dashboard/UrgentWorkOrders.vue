<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { useNow } from '@vueuse/core';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormatDate } from '@/composables/useFormatDate';
import { formatRelative } from '@/lib/format';
import type { UrgentWorkOrder } from '@/types';

/**
 * Dashboard "WO Mendesak": submitted work orders with urgency mendesak,
 * waiting longest first. "Lihat semua" opens the list with the same filter.
 * PROVISIONAL like the status flow: "diajukan" is the awaiting status.
 */
defineProps<{
    /** Undefined while the deferred prop loads. */
    items?: UrgentWorkOrder[];
}>();

const { formatDateTime } = useFormatDate();
const now = useNow({ interval: 60_000 });

const listHref = WorkOrderController.index({
    query: { status: 'diajukan', urgency: 'mendesak' },
});
</script>

<template>
    <section aria-labelledby="urgent-heading" class="flex min-w-0 flex-col">
        <div
            class="flex items-center justify-between gap-2 border-b px-4 py-3 sm:px-6"
        >
            <h2 id="urgent-heading" class="font-medium">WO Mendesak</h2>
            <Button variant="ghost" size="sm" as-child>
                <Link :href="listHref" data-test="urgent-list-link">
                    Lihat semua <ArrowRight />
                </Link>
            </Button>
        </div>

        <ul v-if="items === undefined" class="divide-y">
            <li v-for="n in 3" :key="n" class="space-y-2 px-4 py-3 sm:px-6">
                <Skeleton class="h-4 w-32" />
                <Skeleton class="h-4 w-full" />
            </li>
        </ul>

        <p
            v-else-if="items.length === 0"
            class="px-4 py-10 text-center text-sm text-muted-foreground sm:px-6"
            data-test="urgent-empty"
        >
            Tidak ada WO mendesak yang menunggu.
        </p>

        <ul v-else class="divide-y">
            <li v-for="workOrder in items" :key="workOrder.id">
                <Link
                    :href="WorkOrderController.show(workOrder.id)"
                    class="block px-4 py-3 text-sm transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none sm:px-6"
                >
                    <span class="flex items-baseline justify-between gap-3">
                        <span class="truncate font-mono text-xs">
                            {{ workOrder.number }}
                        </span>
                        <time
                            v-if="workOrder.submitted_at"
                            :datetime="workOrder.submitted_at"
                            :title="formatDateTime(workOrder.submitted_at)"
                            class="shrink-0 text-xs text-muted-foreground"
                            data-test="submitted-at"
                        >
                            {{ formatRelative(workOrder.submitted_at, now) }}
                            <span class="sr-only">
                                ({{ formatDateTime(workOrder.submitted_at) }})
                            </span>
                        </time>
                    </span>
                    <span class="mt-0.5 block truncate font-medium">
                        {{ workOrder.title }}
                    </span>
                    <span class="block truncate text-muted-foreground">
                        {{ workOrder.category }} ·
                        {{ workOrder.requester.name }}
                    </span>
                </Link>
            </li>
        </ul>
    </section>
</template>
