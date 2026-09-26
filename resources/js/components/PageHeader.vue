<script setup lang="ts">
/**
 * Top of every app page: the serif h1, a one-line description (or richer
 * content in the `description` slot), and the page's actions in the
 * `actions` slot, with at most one primary.
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-1">
            <h1 class="font-serif text-3xl">{{ title }}</h1>
            <div
                v-if="description || $slots.description"
                class="text-sm text-muted-foreground"
            >
                <slot name="description">{{ description }}</slot>
            </div>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
