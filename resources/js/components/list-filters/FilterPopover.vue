<script setup lang="ts">
import { SlidersHorizontal } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import FilterCountBadge from './FilterCountBadge.vue';

/**
 * "Filter" button holding a list's less-used filters. The count names how
 * many of them are active. It stays open while they change, since list
 * reloads preserve state.
 */
defineProps<{ count: number }>();
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                class="shrink-0"
                :aria-label="count > 0 ? `Filter, ${count} aktif` : 'Filter'"
                data-test="filter-popover-trigger"
            >
                <SlidersHorizontal /> Filter
                <FilterCountBadge :count="count" />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            class="flex w-80 flex-col gap-4"
            data-test="filter-popover"
        >
            <slot />
        </PopoverContent>
    </Popover>
</template>
