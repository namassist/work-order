<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { eventTone } from '@/lib/activity';
import { cn } from '@/lib/utils';
import type { ActivityEntry } from '@/types';

defineProps<{
    entry: Pick<ActivityEntry, 'event' | 'event_label'>;
}>();
</script>

<template>
    <Badge :class="cn('border-transparent', eventTone(entry.event))">
        {{ entry.event_label }}
    </Badge>
</template>
