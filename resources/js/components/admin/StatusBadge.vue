<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const props = defineProps<{
    isActive: boolean;
    deleted?: boolean;
}>();

const status = computed(() => {
    if (props.deleted) {
        return {
            label: 'Terhapus',
            class: 'bg-destructive/10 text-destructive',
        };
    }

    return props.isActive
        ? { label: 'Aktif', class: 'bg-success text-success-foreground' }
        : {
              label: 'Nonaktif',
              class: 'bg-secondary text-secondary-foreground',
          };
});
</script>

<template>
    <Badge :class="cn('border-transparent', status.class)">
        {{ status.label }}
    </Badge>
</template>
