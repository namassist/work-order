<script setup lang="ts">
import AttachmentRow from '@/components/attachments/AttachmentRow.vue';
import { Badge } from '@/components/ui/badge';
import { useFormatDate } from '@/composables/useFormatDate';
import type { WorkOrderBast } from '@/types';

/**
 * The BAST of a work order (FLOW.md §8): generated as a draft when Rental
 * submits it, and as the final, immutable PDF when the Direktur approves
 * it. The SHA-256 lets anyone check a copy of the final PDF.
 */
defineProps<{
    bast: WorkOrderBast;
}>();

const { formatDateTime } = useFormatDate();
</script>

<template>
    <section
        class="border-t px-4 py-6 sm:px-6"
        aria-labelledby="wo-bast-heading"
    >
        <h2
            id="wo-bast-heading"
            class="mb-4 flex flex-wrap items-center gap-2 font-medium"
        >
            BAST
            <Badge v-if="bast.approved_at" variant="outline">Final</Badge>
            <Badge v-else variant="secondary">Draf</Badge>
        </h2>

        <dl
            class="mb-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3"
        >
            <div>
                <dt class="text-muted-foreground">Nomor BAST</dt>
                <dd class="font-mono">{{ bast.number }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Diajukan</dt>
                <dd>
                    {{ bast.submitted_by }},
                    <span class="tabular-nums">
                        {{ formatDateTime(bast.submitted_at) }}
                    </span>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Disetujui</dt>
                <dd v-if="bast.approved_at">
                    {{ bast.approver_name }},
                    <span class="tabular-nums">
                        {{ formatDateTime(bast.approved_at) }}
                    </span>
                </dd>
                <dd v-else>Menunggu persetujuan Direktur</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Versi template</dt>
                <dd class="tabular-nums">{{ bast.template_version }}</dd>
            </div>
            <div v-if="bast.final_sha256" class="sm:col-span-2">
                <dt class="text-muted-foreground">SHA-256 PDF final</dt>
                <dd class="font-mono text-xs break-all">
                    {{ bast.final_sha256 }}
                </dd>
            </div>
        </dl>

        <ul v-if="bast.file" class="divide-y rounded-lg border">
            <AttachmentRow :attachment="bast.file" />
        </ul>
    </section>
</template>
