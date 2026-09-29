<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { statusToneClass } from '@/lib/workOrderStatus';
import type { PaymentStatusOption } from '@/types';

/**
 * The payment track of a closed work order (FLOW.md §10). Always shown next
 * to the label "Pembayaran", so it is not taken for the work order's status
 * (docs/DESIGN.md › Status pembayaran).
 */
defineProps<{
    status: PaymentStatusOption;
}>();
</script>

<template>
    <Badge :class="cn('border-transparent', statusToneClass(status.tone))">
        {{ status.label }}
    </Badge>
</template>
