<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

/**
 * Placeholder for a list or panel with nothing to show. Put the follow-up
 * action (create, clear filters) in the default slot.
 */
defineProps<{
    icon: LucideIcon;
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex max-w-sm flex-col items-center gap-3 text-center whitespace-normal"
    >
        <component
            :is="icon"
            class="size-5 text-muted-foreground"
            aria-hidden="true"
        />
        <div class="space-y-1">
            <p class="font-medium">{{ title }}</p>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <slot />
    </div>
</template>
