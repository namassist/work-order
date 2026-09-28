<script setup lang="ts">
import { AlertTriangle, Pencil } from '@lucide/vue';
import { computed } from 'vue';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import { Button } from '@/components/ui/button';
import { useFormatDate } from '@/composables/useFormatDate';
import { formatRupiah } from '@/lib/format';
import type { AttachmentPanelData, WorkOrderInvoice } from '@/types';

/**
 * The Penagihan part of the detail page (FLOW.md §8): the invoice, whether
 * it was paid, and its files (invoice, BAST, proof of payment). Before
 * there is an invoice it shows only the BAST, which the target department
 * may add while it works.
 */
const props = defineProps<{
    invoice: WorkOrderInvoice | null;
    attachments: Partial<
        Record<'invoice' | 'bast' | 'bukti_bayar', AttachmentPanelData>
    >;
    canCorrect: boolean;
}>();

defineEmits<{ correct: [] }>();

const { formatCalendarDate } = useFormatDate();

const PANELS = [
    { key: 'invoice', title: 'Invoice' },
    { key: 'bast', title: 'BAST' },
    { key: 'bukti_bayar', title: 'Bukti bayar' },
] as const;

/** The panels the server sent, in this order. */
const panels = computed(() =>
    PANELS.flatMap(({ key, title }) => {
        const data = props.attachments[key];

        return data ? [{ key, title, data }] : [];
    }),
);
</script>

<template>
    <section
        class="border-t px-4 py-6 sm:px-6"
        aria-labelledby="wo-invoice-heading"
    >
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 id="wo-invoice-heading" class="font-medium">
                {{ invoice ? 'Penagihan' : 'BAST' }}
            </h2>
            <Button
                v-if="canCorrect"
                variant="outline"
                size="sm"
                @click="$emit('correct')"
            >
                <Pencil /> Koreksi invoice
            </Button>
        </div>

        <dl
            v-if="invoice"
            class="mb-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3"
        >
            <div>
                <dt class="text-muted-foreground">Nomor invoice</dt>
                <dd class="font-mono">{{ invoice.number }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Tanggal invoice</dt>
                <dd class="tabular-nums">
                    {{ formatCalendarDate(invoice.invoice_date) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Jumlah tagihan</dt>
                <dd class="tabular-nums">
                    {{ invoice.amount ? formatRupiah(invoice.amount) : '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Jatuh tempo</dt>
                <dd class="flex flex-wrap items-center gap-x-2 tabular-nums">
                    {{
                        invoice.due_date
                            ? formatCalendarDate(invoice.due_date)
                            : '—'
                    }}
                    <span
                        v-if="invoice.is_overdue"
                        class="inline-flex items-center gap-1 text-xs font-medium text-destructive"
                    >
                        <AlertTriangle class="size-3.5" aria-hidden="true" />
                        Lewat jatuh tempo
                    </span>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Status pembayaran</dt>
                <dd v-if="invoice.paid_on" class="tabular-nums">
                    Lunas · {{ formatCalendarDate(invoice.paid_on) }}
                </dd>
                <dd v-else>Belum dibayar</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Diterbitkan oleh</dt>
                <dd>{{ invoice.issued_by.name }}</dd>
            </div>
            <div v-if="invoice.corrected_by">
                <dt class="text-muted-foreground">Terakhir dikoreksi oleh</dt>
                <dd>{{ invoice.corrected_by.name }}</dd>
            </div>
            <div v-if="invoice.paid_by">
                <dt class="text-muted-foreground">
                    Pembayaran dikonfirmasi oleh
                </dt>
                <dd>{{ invoice.paid_by.name }}</dd>
            </div>
        </dl>

        <!-- One panel (the BAST before invoicing) spans the width, like Dokumen. -->
        <div :class="['grid gap-6', { 'lg:grid-cols-3': panels.length > 1 }]">
            <div v-for="panel in panels" :key="panel.key">
                <h3 v-if="invoice" class="mb-2 text-sm text-muted-foreground">
                    {{ panel.title }}
                </h3>
                <AttachmentPanel
                    :rules="panel.data.rules"
                    :target="panel.data.target"
                    :items="panel.data.items"
                    :can-upload="panel.data.can.upload"
                    :can-delete="panel.data.can.delete"
                />
            </div>
        </div>
    </section>
</template>
