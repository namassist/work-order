<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';

export type StatItem = {
    label: string;
    /** Undefined while a deferred prop loads; null when not available yet. */
    value: number | null | undefined;
    icon: LucideIcon;
    /** Short muted note under the label, e.g. Terlambat's breakdown. */
    hint?: string;
    /** The list this number counts; the whole cell links to it. */
    href?: string;
};

/**
 * Statistics strip under a page panel's header: big number above its label,
 * a small monochrome icon top-right, cells split by 1px lines. Four columns
 * on desktop, 2×2 on tablets, one column on phones. More than four items
 * (the WO list: total plus every status, 10) stay in two columns on phones
 * instead of one tall column, and take four or five columns from lg (two
 * rows of five for the WO list), so no row is left with empty cells. A cell
 * with an `href` links to the list its number counts.
 */
const props = defineProps<{
    items: StatItem[];
}>();

const columns = computed(() =>
    props.items.length <= 4
        ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4'
        : props.items.length % 5 === 0
          ? 'grid-cols-2 lg:grid-cols-5'
          : 'grid-cols-2 lg:grid-cols-4',
);
</script>

<template>
    <dl :class="['grid gap-px border-b bg-border', columns]">
        <component
            :is="item.href ? Link : 'div'"
            v-for="item in items"
            :key="item.label"
            :href="item.href"
            class="relative flex flex-col gap-1 bg-card px-4 py-5 pr-12 sm:px-6 sm:pr-12"
            :class="
                item.href &&
                'transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none'
            "
            data-test="stat-item"
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
        </component>
    </dl>
</template>
