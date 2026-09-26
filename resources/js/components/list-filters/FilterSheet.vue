<script setup lang="ts">
import { SlidersHorizontal } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import FilterCountBadge from './FilterCountBadge.vue';

/**
 * Narrow-screen "Filter" button: every filter but search, plus the sort, in
 * one sheet. It stays open while they change, since list reloads preserve
 * state.
 */
defineProps<{ count: number }>();
</script>

<template>
    <Sheet>
        <SheetTrigger as-child>
            <Button
                variant="outline"
                class="shrink-0"
                :aria-label="count > 0 ? `Filter, ${count} aktif` : 'Filter'"
                data-test="filter-sheet-trigger"
            >
                <SlidersHorizontal /> Filter
                <FilterCountBadge :count="count" />
            </Button>
        </SheetTrigger>
        <SheetContent class="overflow-y-auto" data-test="filter-sheet">
            <SheetHeader>
                <SheetTitle>Filter</SheetTitle>
                <SheetDescription class="sr-only">
                    Perubahan langsung diterapkan ke daftar.
                </SheetDescription>
            </SheetHeader>
            <div class="flex flex-col gap-4 px-4 pb-6">
                <slot />
            </div>
        </SheetContent>
    </Sheet>
</template>
