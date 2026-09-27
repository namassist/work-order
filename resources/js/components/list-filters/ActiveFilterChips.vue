<script setup lang="ts" generic="Key extends string">
import { X } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * The filters narrowing a list, one removable chip each, under the toolbar.
 * Renders nothing while no filter is active. Sort is not a filter: no chip.
 */
defineProps<{ chips: { key: Key; label: string }[] }>();

const emit = defineEmits<{ remove: [key: Key]; reset: [] }>();
</script>

<template>
    <div
        v-if="chips.length > 0"
        class="flex flex-wrap items-center gap-2"
        data-test="active-filters"
    >
        <span class="sr-only">Filter aktif:</span>
        <button
            v-for="chip in chips"
            :key="chip.key"
            type="button"
            class="inline-flex h-7 max-w-full items-center gap-1 rounded-md border bg-card px-2 text-xs transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden"
            :aria-label="`Hapus filter ${chip.label}`"
            data-test="filter-chip"
            @click="emit('remove', chip.key)"
        >
            <span class="truncate">{{ chip.label }}</span>
            <X class="size-3.5 shrink-0 text-muted-foreground" />
        </button>
        <Button
            variant="link"
            size="sm"
            class="h-7 px-1 text-xs text-muted-foreground"
            data-test="filter-reset"
            @click="emit('reset')"
        >
            Reset semua
        </Button>
    </div>
</template>
