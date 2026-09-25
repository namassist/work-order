<script setup lang="ts">
/**
 * Top of every app page: the serif h1, a one-line description, and the
 * page's single primary action in the `actions` slot.
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
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
