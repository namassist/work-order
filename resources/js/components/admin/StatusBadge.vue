<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { AccountStatus } from '@/types';

const props = defineProps<{
    isActive: boolean;
    deleted?: boolean;
    /** A registration under review shows its review state instead. */
    accountStatus?: AccountStatus;
}>();

const status = computed(() => {
    if (props.deleted) {
        return {
            label: 'Terhapus',
            class: 'bg-destructive/10 text-destructive',
        };
    }

    if (props.accountStatus === 'pending') {
        return {
            label: 'Menunggu review',
            // Short enough for a phone-width table row.
            shortLabel: 'Menunggu',
            class: 'bg-warning text-warning-foreground',
        };
    }

    if (props.accountStatus === 'rejected') {
        return {
            label: 'Ditolak',
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
        <template v-if="'shortLabel' in status">
            <span class="sm:hidden">{{ status.shortLabel }}</span>
            <span class="hidden sm:inline">{{ status.label }}</span>
        </template>
        <template v-else>{{ status.label }}</template>
    </Badge>
</template>
