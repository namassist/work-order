<script setup lang="ts">
/**
 * An urgency as icon plus text (docs/DESIGN.md › Urgensi Work Order): muted
 * for every level but Mendesak, which is semibold in the text colour. Never
 * a badge or a status colour, so it cannot be mistaken for the status.
 */
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { isUrgent, urgencyIcon } from '@/lib/workOrderUrgency';
import type { WorkOrderUrgencyOption } from '@/types';

const props = defineProps<{
    urgency: WorkOrderUrgencyOption;
}>();

const icon = computed(() => urgencyIcon(props.urgency.value));
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-1 whitespace-nowrap',
                isUrgent(urgency.value)
                    ? 'font-semibold text-foreground'
                    : 'text-muted-foreground',
            )
        "
    >
        <component :is="icon" class="size-4 shrink-0" aria-hidden="true" />
        {{ urgency.label }}
    </span>
</template>
