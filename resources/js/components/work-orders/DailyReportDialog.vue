<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, watch } from 'vue';
import WorkOrderDailyReportController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderDailyReportController';
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
import { Textarea } from '@/components/ui/textarea';
import { useFormatDate } from '@/composables/useFormatDate';
import type {
    WorkOrderDailyReport,
    WorkOrderDailyReportSettings,
} from '@/types';

/**
 * Posts a daily report (FLOW.md §7), or edits one: a note and at least one
 * Excel/PDF file or link. The date is chosen only when posting, within the
 * back-dating window; every rule is checked again by the server.
 */
const props = defineProps<{
    workOrderId: number;
    /** The report to edit; null to post a new one. */
    report: WorkOrderDailyReport | null;
    settings: WorkOrderDailyReportSettings;
}>();

const open = defineModel<boolean>('open', { required: true });

const { formatCalendarDate } = useFormatDate();

const editing = computed(() => props.report !== null);

const form = useForm({
    report_date: '',
    note: '',
    links: [] as string[],
    files: [] as File[],
    remove_files: [] as string[],
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        report_date: props.report?.report_date ?? props.settings.today,
        note: props.report?.note ?? '',
        links: props.report ? [...props.report.links] : [''],
        files: [],
        remove_files: [],
    });
    form.reset();
});

const keptFiles = computed(
    () =>
        (props.report?.files.length ?? 0) -
        form.remove_files.length +
        form.files.length,
);
const filledLinks = computed(() =>
    form.links.map((link) => link.trim()).filter((link) => link !== ''),
);
const hasEvidence = computed(
    () => keptFiles.value > 0 || filledLinks.value.length > 0,
);

/** Errors on a list ("links") or on one of its items ("links.0"). */
const errorsFor = (prefix: string) =>
    Object.entries(form.errors as Record<string, string | undefined>)
        .filter(([key]) => key === prefix || key.startsWith(`${prefix}.`))
        .map(([, message]) => message)
        .join(' ') || undefined;

const addLink = () => {
    form.links = [...form.links, ''];
};

const removeLink = (index: number) => {
    form.links = form.links.filter((_, position) => position !== index);
};

const toggleRemoval = (id: string, remove: boolean | 'indeterminate') => {
    form.remove_files =
        remove === true
            ? [...form.remove_files, id]
            : form.remove_files.filter((removed) => removed !== id);
};

const submit = () => {
    form.transform((data) => ({
        // PHP reads uploads only from POST, so the edit (PATCH) is spoofed.
        ...(editing.value
            ? { _method: 'patch', remove_files: data.remove_files }
            : { report_date: data.report_date }),
        note: data.note,
        links: filledLinks.value,
        files: data.files,
    })).post(
        props.report
            ? WorkOrderDailyReportController.update.url({
                  workOrder: props.workOrderId,
                  dailyReport: props.report.id,
              })
            : WorkOrderDailyReportController.store.url(props.workOrderId),
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
                            report
                                ? `Ubah laporan ${formatCalendarDate(report.report_date)}`
                                : 'Tambah laporan harian'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Isi catatan singkat dan minimal satu berkas Excel/PDF
                        atau tautan timesheet. Perubahan tercatat di riwayat
                        work order.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="!report" class="grid gap-2 sm:max-w-xs">
                    <Label for="report-date">Tanggal laporan</Label>
                    <Input
                        id="report-date"
                        v-model="form.report_date"
                        type="date"
                        :min="settings.earliest_date"
                        :max="settings.today"
                        required
                    />
                    <InputError :message="form.errors.report_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="report-note">Catatan</Label>
                    <Textarea
                        id="report-note"
                        v-model="form.note"
                        rows="3"
                        :maxlength="settings.note_max_length"
                        required
                    />
                    <InputError :message="form.errors.note" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        Tautan timesheet
                        <span class="font-normal text-muted-foreground">
                            (http/https<template
                                v-if="settings.link_domains.length > 0"
                                >,
                                {{ settings.link_domains.join(', ') }}</template
                            >)
                        </span>
                    </legend>
                    <div
                        v-for="(_, index) in form.links"
                        :key="index"
                        class="flex items-center gap-2"
                    >
                        <Input
                            :id="`report-link-${index}`"
                            v-model="form.links[index]"
                            type="url"
                            inputmode="url"
                            placeholder="https://…sharepoint.com/…"
                            :maxlength="settings.link_max_length"
                            :aria-label="`Tautan ${index + 1}`"
                            autocomplete="off"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Hapus tautan ${index + 1}`"
                            @click="removeLink(index)"
                        >
                            <X />
                        </Button>
                    </div>
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="form.links.length >= settings.max_links"
                            @click="addLink"
                        >
                            <Plus /> Tambah tautan
                        </Button>
                    </div>
                    <InputError :message="errorsFor('links')" />
                </fieldset>

                <fieldset
                    v-if="report && report.files.length > 0"
                    class="grid gap-2"
                >
                    <legend class="mb-2 text-sm font-medium">
                        Berkas saat ini
                    </legend>
                    <div
                        v-for="file in report.files"
                        :key="file.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :id="`remove-report-file-${file.id}`"
                            :model-value="form.remove_files.includes(file.id)"
                            @update:model-value="toggleRemoval(file.id, $event)"
                        />
                        <Label
                            :for="`remove-report-file-${file.id}`"
                            class="font-normal"
                        >
                            Hapus {{ file.name }}
                        </Label>
                    </div>
                </fieldset>

                <div class="grid gap-2">
                    <p class="text-sm font-medium">
                        {{ report ? 'Tambah berkas' : 'Berkas timesheet' }}
                    </p>
                    <AttachmentPanel
                        v-model:pending="form.files"
                        :rules="settings.files"
                        :can-upload="!form.processing"
                        :progress="form.progress?.percentage ?? null"
                        :error="errorsFor('files')"
                    />
                </div>

                <InputError :message="errorsFor('remove_files')" />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="form.processing || !hasEvidence"
                    >
                        {{ report ? 'Simpan perubahan' : 'Simpan laporan' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
