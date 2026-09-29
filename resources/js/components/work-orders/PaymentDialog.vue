<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import WorkOrderPaymentController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderPaymentController';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { calendarDateIn } from '@/lib/format';
import type { AttachmentRules, WorkOrderInvoice } from '@/types';

/**
 * Confirms the invoice was paid (payment Ditagih → Lunas, FLOW.md §10): the
 * payment date, from the invoice date up to today (WITA), and optional
 * proof of payment.
 */
const props = defineProps<{
    workOrderId: number;
    displayNumber: string;
    invoice: WorkOrderInvoice;
    rules: AttachmentRules;
}>();

const open = defineModel<boolean>('open', { required: true });

const page = usePage();
const today = () => calendarDateIn(new Date(), page.props.displayTimezone);

const form = useForm({ paid_on: '', proof_files: [] as File[] });

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({ paid_on: today(), proof_files: [] });
    form.reset();
});

/** Errors on a list ("proof_files") or on one of its files ("proof_files.0"). */
const errorsFor = (prefix: string) =>
    Object.entries(form.errors as Record<string, string | undefined>)
        .filter(([key]) => key.startsWith(prefix))
        .map(([, message]) => message)
        .join(' ') || undefined;

const submit = () => {
    form.post(WorkOrderPaymentController.store.url(props.workOrderId), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        Konfirmasi pembayaran {{ displayNumber }}?
                    </DialogTitle>
                    <DialogDescription>
                        Invoice
                        <span class="font-mono">{{ invoice.number }}</span>
                        ditandai lunas. Setelah itu komentar dan berkas work
                        order tidak dapat diubah lagi.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="payment-date">Tanggal pembayaran</Label>
                    <Input
                        id="payment-date"
                        v-model="form.paid_on"
                        type="date"
                        :min="invoice.invoice_date"
                        :max="today()"
                        required
                    />
                    <InputError :message="form.errors.paid_on" />
                </div>

                <div class="grid gap-2">
                    <p class="text-sm font-medium">
                        Bukti bayar
                        <span class="font-normal text-muted-foreground">
                            (opsional)
                        </span>
                    </p>
                    <AttachmentPanel
                        v-model:pending="form.proof_files"
                        :rules="rules"
                        :can-upload="!form.processing"
                        :progress="form.progress?.percentage ?? null"
                        :error="errorsFor('proof_files')"
                    />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="form.processing">
                        Konfirmasi pembayaran
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
