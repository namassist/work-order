<script setup lang="ts">
import { AlertTriangle, BadgeCheck, Pencil, ReceiptText } from '@lucide/vue';
import { computed } from 'vue';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import { Button } from '@/components/ui/button';
import PaymentStatusBadge from '@/components/work-orders/PaymentStatusBadge.vue';
import { useFormatDate } from '@/composables/useFormatDate';
import { formatRupiah } from '@/lib/format';
import type {
    AttachmentPanelData,
    PaymentStatusOption,
    WorkOrderInvoice,
} from '@/types';

/**
 * The payment track of a closed work order (FLOW.md §10): its payment
 * status, Finance's next action, the invoice once billed, and its files
 * (invoice, proof of payment).
 */
const props = defineProps<{
    paymentStatus: PaymentStatusOption;
    invoice: WorkOrderInvoice | null;
    attachments: Partial<
        Record<'invoice' | 'bukti_bayar', AttachmentPanelData>
    >;
    can: { bill: boolean; correctInvoice: boolean; confirmPayment: boolean };
    /** Why this user may not confirm the payment (segregation of duties). */
    blockedReason: string | null;
}>();

defineEmits<{ bill: []; correct: []; confirm: [] }>();

const { formatCalendarDate } = useFormatDate();

const PANELS = [
    { key: 'invoice', title: 'Invoice' },
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
        aria-labelledby="wo-payment-heading"
    >
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2
                id="wo-payment-heading"
                class="flex flex-wrap items-center gap-2 font-medium"
            >
                Pembayaran
                <PaymentStatusBadge :status="paymentStatus" />
            </h2>
            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="can.bill" @click="$emit('bill')">
                    <ReceiptText /> Terbitkan invoice
                </Button>
                <Button
                    v-if="can.confirmPayment"
                    :disabled="blockedReason !== null"
                    @click="$emit('confirm')"
                >
                    <BadgeCheck /> Konfirmasi pembayaran
                </Button>
                <Button
                    v-if="can.correctInvoice"
                    variant="outline"
                    @click="$emit('correct')"
                >
                    <Pencil /> Koreksi invoice
                </Button>
            </div>
            <p
                v-if="can.confirmPayment && blockedReason"
                class="basis-full text-right text-xs text-muted-foreground"
            >
                {{ blockedReason }}
            </p>
        </div>

        <p v-if="!invoice" class="text-sm text-muted-foreground">
            Belum ada invoice.
        </p>

        <dl
            v-else
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
                <dt class="text-muted-foreground">Tanggal pembayaran</dt>
                <dd class="tabular-nums">
                    {{
                        invoice.paid_on
                            ? formatCalendarDate(invoice.paid_on)
                            : '—'
                    }}
                </dd>
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

        <div v-if="panels.length > 0" class="grid gap-6 lg:grid-cols-2">
            <div v-for="panel in panels" :key="panel.key">
                <h3 class="mb-2 text-sm text-muted-foreground">
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
