<script setup lang="ts">
/**
 * Toolbar between a list's statistics and its table: the page's primary
 * action on the left (`actions` slot), search and filters on the right.
 */
</script>

<template>
    <div
        class="flex flex-col gap-3 border-b px-4 py-4 sm:px-6 xl:flex-row xl:items-start xl:justify-between"
    >
        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
        <div
            class="flex flex-wrap items-center gap-3 xl:ml-auto xl:justify-end"
        >
            <slot />
        </div>
    </div>
</template>
