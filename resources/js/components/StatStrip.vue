<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';

export type StatItem = {
    label: string;
    /** Undefined while a deferred prop loads; null when not available yet. */
    value: number | null | undefined;
    icon: LucideIcon;
    /** Short muted note under the label, e.g. "Segera hadir". */
    hint?: string;
};

/**
 * Statistics strip under a page panel's header: big number above its label,
 * a small monochrome icon top-right, cells split by 1px lines. Four columns
 * on desktop, 2×2 on tablets, one column on phones; more than four items
 * (the WO list: total plus every status) take six columns on wide screens
 * and three below that, so no row is left with empty cells.
 */
const props = defineProps<{
    items: StatItem[];
}>();

const columns = computed(() =>
    props.items.length > 4
        ? 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6'
        : 'sm:grid-cols-2 lg:grid-cols-4',
);
</script>

<template>
    <dl :class="['grid grid-cols-1 gap-px border-b bg-border', columns]">
        <div
            v-for="item in items"
            :key="item.label"
            class="relative flex flex-col gap-1 bg-card px-4 py-5 pr-12 sm:px-6 sm:pr-12"
        >
            <dt class="order-2 text-sm text-muted-foreground">
                {{ item.label }}
                <span v-if="item.hint" class="block text-xs">
                    {{ item.hint }}
                </span>
            </dt>
            <dd class="order-1 text-3xl font-semibold tabular-nums">
                <Skeleton v-if="item.value === undefined" class="h-9 w-12" />
                <template v-else-if="item.value === null">
                    <span class="text-muted-foreground" aria-hidden="true"
                        >—</span
                    >
                    <span class="sr-only">Belum tersedia</span>
                </template>
                <template v-else>{{ item.value }}</template>
            </dd>
            <component
                :is="item.icon"
                class="absolute top-5 right-4 size-4 text-muted-foreground sm:right-6"
                aria-hidden="true"
            />
        </div>
    </dl>
</template>
