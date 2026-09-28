<script setup lang="ts">
import { VisAxis, VisStackedBar, VisXYContainer } from '@unovis/vue';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    componentToString,
} from '@/components/ui/chart';
import { formatShortCalendarDate } from '@/lib/format';
import { statusChartColor } from '@/lib/workOrderStatus';
import type { RequestOverview } from '@/types';
import RequestOverviewTooltip from './RequestOverviewTooltip.vue';

/**
 * The stacked bars of "Ringkasan Pengajuan". Mounted only once the data is
 * there (and re-mounted per period), because the tooltip template captures
 * the series config at setup. Hidden from screen readers; the parent has
 * the table.
 */
const props = defineProps<{
    overview: RequestOverview;
}>();

type Row = { date: string } & Record<string, number | string>;

const statuses = props.overview.statuses;

const rows: Row[] = props.overview.series.map((day) => ({
    date: day.date,
    ...day.counts,
}));

const config: ChartConfig = Object.fromEntries(
    statuses.map((status) => [
        status.value,
        { label: status.label, color: statusChartColor(status) },
    ]),
);

const indexOf = (_: Row, index: number): number => index;
const yAccessors = statuses.map(
    (status) =>
        (row: Row): number =>
            Number(row[status.value] ?? 0),
);
const colors = statuses.map((status) => `var(--color-${status.value})`);

/** Every day for a week; every fifth day, counted back from today, for 30. */
const step = rows.length > 7 ? 5 : 1;
const tickValues = rows
    .map((_, index) => index)
    .filter((index) => (rows.length - 1 - index) % step === 0);

const tickLabel = (index: number): string =>
    rows[index] ? formatShortCalendarDate(rows[index].date) : '';

/** Whole-number y ticks (counts), at most five, so gridlines match labels. */
const highest = Math.max(
    1,
    ...rows.map((row) =>
        yAccessors.reduce((sum, accessor) => sum + accessor(row), 0),
    ),
);
const yStep = Math.ceil(highest / 4);
const yTicks = Array.from(
    { length: Math.ceil(highest / yStep) + 1 },
    (_, index) => index * yStep,
);

const tooltip = componentToString(config, RequestOverviewTooltip);
</script>

<template>
    <ChartContainer
        :config="config"
        class="aspect-auto h-64"
        :style="{
            '--vis-stacked-bar-stroke-color': 'var(--card)',
            '--vis-stacked-bar-stroke-width': '2px',
            '--vis-axis-grid-color': 'var(--border)',
            '--vis-axis-tick-label-font-size': '11px',
        }"
        aria-hidden="true"
    >
        <VisXYContainer
            :data="rows"
            :yDomain="[0, yTicks[yTicks.length - 1]]"
            :padding="{ top: 8 }"
        >
            <VisStackedBar
                :x="indexOf"
                :y="yAccessors"
                :color="colors"
                :rounded-corners="4"
                :bar-padding="0.3"
                :bar-max-width="40"
            />
            <VisAxis
                type="x"
                :tick-values="tickValues"
                :tick-format="tickLabel"
                :tick-line="false"
                :domain-line="false"
                :grid-line="false"
            />
            <VisAxis
                type="y"
                :tick-values="yTicks"
                :tick-line="false"
                :domain-line="false"
            />
            <ChartTooltip />
            <ChartCrosshair
                :x="indexOf"
                :yStacked="yAccessors"
                :template="tooltip"
                color="#0000"
            />
        </VisXYContainer>
    </ChartContainer>
</template>
