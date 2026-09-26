<script setup lang="ts">
import { useFormatDate } from '@/composables/useFormatDate';
import type { StatusHistoryEntry } from '@/types';

/**
 * Status changes of one work order, oldest first.
 */
defineProps<{
    entries: StatusHistoryEntry[];
}>();

const { formatDateTime } = useFormatDate();
</script>

<template>
    <ol class="relative space-y-6 border-l pl-6">
        <li v-for="entry in entries" :key="entry.id" class="relative">
            <span
                class="absolute top-1.5 -left-[1.9rem] size-2.5 rounded-full border-2 border-card bg-primary"
                aria-hidden="true"
            />
            <p class="text-sm font-medium">
                <template v-if="entry.from">
                    {{ entry.from.label }} &rarr; {{ entry.to.label }}
                </template>
                <template v-else>Dibuat sebagai {{ entry.to.label }}</template>
            </p>
            <p class="text-sm text-muted-foreground">
                {{ entry.user.name }} &middot;
                <time :datetime="entry.created_at" class="tabular-nums">
                    {{ formatDateTime(entry.created_at) }}
                </time>
            </p>
            <p
                v-if="entry.note"
                class="mt-2 rounded-lg bg-muted px-3 py-2 text-sm whitespace-pre-line"
            >
                {{ entry.note }}
            </p>
        </li>
    </ol>
</template>
