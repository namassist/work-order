<script setup lang="ts">
import { computed } from 'vue';
import type { ChartConfig } from '@/components/ui/chart';
import { formatCalendarDate } from '@/lib/format';

/**
 * Tooltip of the "Ringkasan Pengajuan" chart: the day, the count of every
 * status (zeros included), and the total. Rendered to an HTML string by
 * componentToString, outside the app, so it must not use app context.
 */
const props = defineProps<{
    payload?: Record<string, string | number>;
    config?: ChartConfig;
}>();

const rows = computed(() =>
    Object.entries(props.config ?? {}).map(([key, item]) => ({
        key,
        label: String(item.label ?? key),
        color: item.color,
        value: Number(props.payload?.[key] ?? 0),
    })),
);

const total = computed(() =>
    rows.value.reduce((sum, row) => sum + row.value, 0),
);
</script>

<template>
    <div
        class="grid min-w-36 gap-1.5 rounded-lg border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-sm"
    >
        <div class="font-medium">
            {{ formatCalendarDate(String(payload?.date ?? '')) }}
        </div>
        <div v-for="row in rows" :key="row.key" class="flex items-center gap-2">
            <span
                class="size-2.5 shrink-0 rounded-xs"
                :style="{ backgroundColor: row.color }"
            />
            <span class="flex-1 text-muted-foreground">{{ row.label }}</span>
            <span class="font-medium tabular-nums">{{ row.value }}</span>
        </div>
        <div class="flex justify-between gap-2 border-t pt-1.5">
            <span class="text-muted-foreground">Total</span>
            <span class="font-medium tabular-nums">{{ total }}</span>
        </div>
    </div>
</template>
