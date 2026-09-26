<script setup lang="ts">
/**
 * A list's filter toolbar (docs/DESIGN.md › Toolbar filter), between its
 * statistics and its table: the primary action on the left (`actions`), the
 * controls on the right. The default slot holds the search, always visible.
 * From `xl`, the `filters` slot (the most used filters plus a FilterPopover
 * for the rest) and the `sort` slot (far right, after a divider) show inline;
 * below `xl` they give way to the `sheet` slot, one FilterSheet holding all of
 * them. The controls wrap under the actions as one row, never split. The
 * `chips` slot (ActiveFilterChips) runs underneath.
 */
</script>

<template>
    <div class="flex flex-col gap-3 border-b px-4 py-4 sm:px-6">
        <div class="flex flex-wrap items-center gap-3">
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
            <div class="flex flex-1 items-center justify-end gap-3">
                <slot />
                <div v-if="$slots.filters" class="hidden xl:contents">
                    <slot name="filters" />
                </div>
                <div v-if="$slots.sheet" class="xl:hidden">
                    <slot name="sheet" />
                </div>
                <div
                    v-if="$slots.sort"
                    class="hidden shrink-0 items-center gap-3 xl:flex"
                >
                    <span class="h-6 w-px bg-border" aria-hidden="true" />
                    <slot name="sort" />
                </div>
            </div>
        </div>
        <slot name="chips" />
    </div>
</template>
