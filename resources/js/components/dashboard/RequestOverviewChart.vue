<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCalendarDate } from '@/lib/format';
import { statusToneChartColor } from '@/lib/workOrderStatus';
import type { RequestOverview } from '@/types';

/**
 * Dashboard "Ringkasan Pengajuan": work orders created per WITA day, stacked
 * by current status, over 7 or 30 days. Values live in the tooltip, the
 * legend, and a table for screen readers; the bars carry no numbers.
 */
const props = defineProps<{
    /** Undefined while the deferred prop loads. */
    overview?: RequestOverview;
}>();

/** The chart library is large; load it only when there are bars to draw. */
const RequestOverviewBars = defineAsyncComponent(
    () => import('./RequestOverviewBars.vue'),
);

const PERIODS = ['7', '30'];

const period = ref(String(props.overview?.days ?? PERIODS[0]));
const reloading = ref(false);

watch(
    () => props.overview?.days,
    (days) => {
        if (days !== undefined) {
            period.value = String(days);
        }
    },
);

function changePeriod(value: unknown): void {
    if (typeof value !== 'string' || value === String(props.overview?.days)) {
        return;
    }

    period.value = value;
    router.reload({
        only: ['requestOverview'],
        data: { period: value },
        onStart: () => (reloading.value = true),
        onFinish: () => (reloading.value = false),
    });
}

const statuses = computed(() => props.overview?.statuses ?? []);

const totals = computed(() =>
    statuses.value.map((status) => ({
        ...status,
        color: statusToneChartColor(status.tone),
        total:
            props.overview?.series.reduce(
                (sum, day) => sum + (day.counts[status.value] ?? 0),
                0,
            ) ?? 0,
    })),
);

const isEmpty = computed(() => totals.value.every((status) => !status.total));
</script>

<template>
    <section
        aria-labelledby="request-overview-heading"
        class="flex min-w-0 flex-col"
    >
        <div
            class="flex items-center justify-between gap-2 border-b px-4 py-3 sm:px-6"
        >
            <h2 id="request-overview-heading" class="font-medium">
                Ringkasan Pengajuan
            </h2>
            <Select
                :model-value="period"
                :disabled="overview === undefined"
                @update:model-value="changePeriod"
            >
                <SelectTrigger
                    size="sm"
                    class="w-28"
                    aria-label="Periode ringkasan"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="days in PERIODS"
                        :key="days"
                        :value="days"
                    >
                        {{ days }} hari
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="px-4 py-4 sm:px-6">
            <Skeleton v-if="overview === undefined" class="h-64 w-full" />

            <p
                v-else-if="isEmpty"
                class="flex h-64 items-center justify-center text-sm text-muted-foreground"
            >
                Belum ada WO dibuat dalam {{ overview.days }} hari terakhir.
            </p>

            <template v-else>
                <RequestOverviewBars
                    :key="overview.days"
                    :overview="overview"
                    class="transition-opacity duration-150 motion-reduce:transition-none"
                    :class="{ 'opacity-60': reloading }"
                />

                <ul
                    class="mt-3 flex flex-wrap justify-center gap-x-5 gap-y-1 text-xs"
                    aria-hidden="true"
                >
                    <li
                        v-for="status in totals"
                        :key="status.value"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="size-2.5 rounded-xs"
                            :style="{ backgroundColor: status.color }"
                        />
                        <span class="text-muted-foreground">
                            {{ status.label }}
                        </span>
                        <span class="font-medium tabular-nums">
                            {{ status.total }}
                        </span>
                    </li>
                </ul>

                <table class="sr-only" data-test="overview-table">
                    <caption>
                        Jumlah WO dibuat per hari menurut status,
                        {{
                            overview.days
                        }}
                        hari terakhir
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Tanggal</th>
                            <th
                                v-for="status in statuses"
                                :key="status.value"
                                scope="col"
                            >
                                {{ status.label }}
                            </th>
                            <th scope="col">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="day in overview.series" :key="day.date">
                            <th scope="row">
                                {{ formatCalendarDate(day.date) }}
                            </th>
                            <td v-for="status in statuses" :key="status.value">
                                {{ day.counts[status.value] ?? 0 }}
                            </td>
                            <td>
                                {{
                                    Object.values(day.counts).reduce(
                                        (sum, count) => sum + count,
                                        0,
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </div>
    </section>
</template>
