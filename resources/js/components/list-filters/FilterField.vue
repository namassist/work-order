<script setup lang="ts">
import { Label } from '@/components/ui/label';

/** A labelled filter inside a Filter popover or sheet. */
defineProps<{ label: string; for?: string }>();
</script>

<template>
    <div class="flex flex-col gap-2">
        <Label :for="$props.for">{{ label }}</Label>
        <slot />
    </div>
</template>
