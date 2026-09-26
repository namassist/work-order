<script setup lang="ts">
/** How many filters behind a "Filter" button are active; hidden at 0. */
defineProps<{ count: number }>();
</script>

<template>
    <span
        v-if="count > 0"
        class="inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs leading-5 font-medium text-primary-foreground tabular-nums"
        aria-hidden="true"
        data-test="filter-count"
    >
        {{ count }}
    </span>
</template>
