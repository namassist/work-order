<script setup lang="ts">
import CommentForm from '@/components/work-orders/CommentForm.vue';
import TimelineCommentEntry from '@/components/work-orders/TimelineCommentEntry.vue';
import { useFormatDate } from '@/composables/useFormatDate';
import type { TimelineEntry, WorkOrderCommentSettings } from '@/types';

/**
 * Status changes and comments of one work order, oldest first, with the
 * comment form at the bottom. Transition notes belong to their status entry.
 */
defineProps<{
    entries: TimelineEntry[];
    workOrderId: number;
    canComment: boolean;
    comments: WorkOrderCommentSettings;
}>();

const { formatDateTime } = useFormatDate();
</script>

<template>
    <div class="space-y-6">
        <ol v-if="entries.length > 0" class="relative space-y-6 border-l pl-6">
            <li
                v-for="entry in entries"
                :key="`${entry.type}-${entry.id}`"
                class="relative"
            >
                <template v-if="entry.type === 'status'">
                    <span
                        class="absolute top-1.5 -left-[1.9rem] size-2.5 rounded-full border-2 border-card bg-primary"
                        aria-hidden="true"
                    />
                    <p class="text-sm font-medium">
                        <template v-if="entry.from">
                            {{ entry.from.label }} &rarr; {{ entry.to.label }}
                        </template>
                        <template v-else>
                            Dibuat sebagai {{ entry.to.label }}
                        </template>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ entry.user.name }} &middot;
                        <time :datetime="entry.created_at" class="tabular-nums">
                            {{ formatDateTime(entry.created_at) }}
                        </time>
                    </p>
                    <p
                        v-if="entry.note"
                        class="mt-2 rounded-lg bg-muted px-3 py-2 text-sm break-words whitespace-pre-line"
                    >
                        {{ entry.note }}
                    </p>
                </template>
                <template v-else>
                    <span
                        class="absolute top-1.5 -left-[1.9rem] size-2.5 rounded-full border-2 border-muted-foreground bg-card"
                        aria-hidden="true"
                    />
                    <TimelineCommentEntry
                        :entry="entry"
                        :work-order-id="workOrderId"
                        :max-length="comments.max_length"
                    />
                </template>
            </li>
        </ol>

        <CommentForm
            v-if="canComment"
            :work-order-id="workOrderId"
            :max-length="comments.max_length"
        />
        <p v-else-if="comments.read_only" class="text-sm text-muted-foreground">
            Komentar work order ini hanya dapat dibaca.
        </p>
    </div>
</template>
