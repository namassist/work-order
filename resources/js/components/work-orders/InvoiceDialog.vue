<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import WorkOrderInvoiceController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderInvoiceController';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { calendarDateIn, formatRupiah, parseRupiahInput } from '@/lib/format';
import type { Attachment, AttachmentRules, WorkOrderInvoice } from '@/types';

/**
 * Bills a closed work order (payment Belum ditagih → Ditagih, FLOW.md §10),
 * or corrects the invoice while it waits for payment. Invoice files are
 * required when billing; a correction may add files and remove existing
 * ones, as long as one invoice file stays (the server checks it).
 */
const props = defineProps<{
    workOrderId: number;
    displayNumber: string;
    /** The invoice to correct; null to issue a new one. */
    invoice: WorkOrderInvoice | null;
    rules: AttachmentRules;
    /** The current invoice files, offered for removal when correcting. */
    files?: Attachment[];
}>();

const open = defineModel<boolean>('open', { required: true });

const page = usePage();
const today = () => calendarDateIn(new Date(), page.props.displayTimezone);

const correcting = computed(() => props.invoice !== null);

const form = useForm({
    invoice_number: '',
    invoice_date: '',
    amount: '',
    due_date: '',
    invoice_files: [] as File[],
    remove_files: [] as string[],
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        invoice_number: props.invoice?.number ?? '',
        invoice_date: props.invoice?.invoice_date ?? today(),
        amount: props.invoice?.amount
            ? formatRupiah(props.invoice.amount).replace(/^Rp /, '')
            : '',
        due_date: props.invoice?.due_date ?? '',
        invoice_files: [],
        remove_files: [],
    });
    form.reset();
});

const parsedAmount = computed(() => parseRupiahInput(form.amount));
const amountHint = computed(() => {
    if (form.amount.trim() === '') {
        return 'Rupiah, mis. 1.500.000 atau 1.500.000,50.';
    }

    return parsedAmount.value
        ? formatRupiah(parsedAmount.value)
        : 'Bukan jumlah rupiah yang valid.';
});

/** Errors on a list ("invoice_files") or on one of its files ("invoice_files.0"). */
const errorsFor = (prefix: string) =>
    Object.entries(form.errors as Record<string, string | undefined>)
        .filter(([key]) => key.startsWith(prefix))
        .map(([, message]) => message)
        .join(' ') || undefined;

const toggleRemoval = (id: string, remove: boolean | 'indeterminate') => {
    form.remove_files =
        remove === true
            ? [...form.remove_files, id]
            : form.remove_files.filter((removed) => removed !== id);
};

const submit = () => {
    form.transform((data) => ({
        ...data,
        // Sent as typed when it is not an amount, so the server names the problem.
        amount:
            data.amount.trim() === ''
                ? null
                : (parseRupiahInput(data.amount) ?? data.amount),
        due_date: data.due_date || null,
        // PHP reads uploads only from POST, so the correction (PATCH) is spoofed.
        ...(correcting.value ? { _method: 'patch' } : { remove_files: [] }),
    })).post(
        correcting.value
            ? WorkOrderInvoiceController.update.url(props.workOrderId)
            : WorkOrderInvoiceController.store.url(props.workOrderId),
        {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                open.value = false;
            },
        },
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            correcting
                                ? `Koreksi invoice ${displayNumber}`
                                : `Terbitkan invoice ${displayNumber}?`
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            correcting
                                ? 'Perubahan tercatat di riwayat work order.'
                                : 'Status pembayaran menjadi Ditagih. Status work order tetap Closed.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="invoice-number">Nomor invoice</Label>
                        <Input
                            id="invoice-number"
                            v-model="form.invoice_number"
                            class="font-mono"
                            maxlength="100"
                            required
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.invoice_number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="invoice-date">Tanggal invoice</Label>
                        <Input
                            id="invoice-date"
                            v-model="form.invoice_date"
                            type="date"
                            :max="today()"
                            required
                        />
                        <InputError :message="form.errors.invoice_date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="invoice-amount">
                            Jumlah tagihan
                            <span class="font-normal text-muted-foreground">
                                (opsional)
                            </span>
                        </Label>
                        <Input
                            id="invoice-amount"
                            v-model="form.amount"
                            class="tabular-nums"
                            inputmode="decimal"
                            placeholder="1.500.000"
                            autocomplete="off"
                            aria-describedby="invoice-amount-hint"
                        />
                        <p
                            id="invoice-amount-hint"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{ amountHint }}
                        </p>
                        <InputError :message="form.errors.amount" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="invoice-due-date">
                            Jatuh tempo
                            <span class="font-normal text-muted-foreground">
                                (opsional)
                            </span>
                        </Label>
                        <Input
                            id="invoice-due-date"
                            v-model="form.due_date"
                            type="date"
                            :min="form.invoice_date || undefined"
                        />
                        <InputError :message="form.errors.due_date" />
                    </div>
                </div>

                <fieldset
                    v-if="correcting && (files ?? []).length > 0"
                    class="grid gap-2"
                >
                    <legend class="mb-2 text-sm font-medium">
                        Berkas saat ini
                    </legend>
                    <div
                        v-for="file in files"
                        :key="file.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :id="`remove-${file.id}`"
                            :model-value="form.remove_files.includes(file.id)"
                            @update:model-value="toggleRemoval(file.id, $event)"
                        />
                        <Label :for="`remove-${file.id}`" class="font-normal">
                            Hapus {{ file.name }}
                        </Label>
                    </div>
                </fieldset>

                <div class="grid gap-2">
                    <p class="text-sm font-medium">
                        {{
                            correcting
                                ? 'Tambah berkas invoice'
                                : 'Berkas invoice'
                        }}
                    </p>
                    <AttachmentPanel
                        v-model:pending="form.invoice_files"
                        :rules="rules"
                        :can-upload="!form.processing"
                        :progress="form.progress?.percentage ?? null"
                        :error="errorsFor('invoice_files')"
                    />
                </div>

                <InputError :message="errorsFor('remove_files')" />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            (!correcting && form.invoice_files.length === 0)
                        "
                    >
                        {{
                            correcting ? 'Simpan koreksi' : 'Terbitkan invoice'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
