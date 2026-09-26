<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { statusToneClass } from '@/lib/workOrderStatus';
import type { WorkOrderStatusOption } from '@/types';

defineProps<{
    status: WorkOrderStatusOption;
}>();
</script>

<template>
    <Badge :class="cn('border-transparent', statusToneClass(status.tone))">
        {{ status.label }}
    </Badge>
</template>
