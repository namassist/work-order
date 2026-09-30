<script setup lang="ts">
import {
    Check,
    ClipboardList,
    ClipboardX,
    Clock,
    ExternalLink,
    Minus,
    Pencil,
    Plus,
    X,
} from '@lucide/vue';
import { ref } from 'vue';
import AttachmentRow from '@/components/attachments/AttachmentRow.vue';
import { Button } from '@/components/ui/button';
import DailyReportDialog from '@/components/work-orders/DailyReportDialog.vue';
import { useFormatDate } from '@/composables/useFormatDate';
import { dayStateLabel, linkLabel, safeHttpUrl } from '@/lib/dailyReport';
import { formatCalendarWeekday } from '@/lib/format';
import type {
    WorkOrderDailyReport,
    WorkOrderDailyReportDay,
    WorkOrderDailyReportSettings,
} from '@/types';

/**
 * "Laporan Harian" on the work order detail page (FLOW.md §7): the recent
 * working days as a strip (reported, missing, pending, not required), then
 * the reports newest first with their note, files, and links. Reports stay
 * out of the timeline; their changes show in Riwayat.
 */
defineProps<{
    workOrderId: number;
    reports: WorkOrderDailyReport[];
    days: WorkOrderDailyReportDay[];
    settings: WorkOrderDailyReportSettings;
    /** No report today after the cutoff on a working day ("Belum lapor"). */
    missing: boolean;
    canReport: boolean;
}>();

const { formatCalendarDate, formatDateTime } = useFormatDate();

const dialogOpen = ref(false);
const editing = ref<WorkOrderDailyReport | null>(null);

const openDialog = (report: WorkOrderDailyReport | null) => {
    editing.value = report;
    dialogOpen.value = true;
};

const DAY_ICONS = {
    reported: Check,
    missing: X,
    pending: Clock,
    not_required: Minus,
} as const;

const DAY_CLASSES: Record<WorkOrderDailyReportDay['state'], string> = {
    reported: 'border-success text-success',
    missing: 'border-destructive text-destructive',
    pending: 'border-border text-foreground',
    not_required: 'border-transparent text-muted-foreground',
};
</script>

<template>
    <section
        class="border-t px-4 py-6 sm:px-6"
        aria-labelledby="wo-daily-reports-heading"
    >
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 id="wo-daily-reports-heading" class="font-medium">
                Laporan Harian
            </h2>
            <Button v-if="canReport" size="sm" @click="openDialog(null)">
                <Plus /> Tambah laporan
            </Button>
        </div>

        <p
            v-if="missing"
            class="mb-4 flex items-center gap-2 text-sm font-medium"
            data-test="daily-report-missing"
        >
            <ClipboardX class="size-4 text-destructive" aria-hidden="true" />
            Belum lapor hari ini.
        </p>

        <ol
            v-if="days.length > 0"
            class="mb-6 grid max-w-3xl grid-cols-5 gap-2 sm:grid-cols-10"
            aria-label="Laporan per hari kerja"
        >
            <li
                v-for="day in days"
                :key="day.date"
                class="flex flex-col items-center gap-1 text-xs"
                :title="`${formatCalendarDate(day.date)}: ${dayStateLabel(day.state)}`"
            >
                <span class="text-muted-foreground" aria-hidden="true">
                    {{ formatCalendarWeekday(day.date) }}
                </span>
                <span
                    class="flex size-8 items-center justify-center rounded-md border"
                    :class="DAY_CLASSES[day.state]"
                >
                    <component
                        :is="DAY_ICONS[day.state]"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <span class="sr-only">
                        {{ formatCalendarDate(day.date) }}:
                        {{ dayStateLabel(day.state) }}
                    </span>
                </span>
                <span class="tabular-nums" aria-hidden="true">
                    {{ day.date.slice(8) }}
                </span>
            </li>
        </ol>

        <p
            v-if="reports.length === 0"
            class="flex items-center gap-2 text-sm text-muted-foreground"
        >
            <ClipboardList class="size-4" aria-hidden="true" />
            Belum ada laporan harian.
        </p>

        <ul v-else class="max-w-3xl divide-y">
            <li
                v-for="report in reports"
                :key="report.id"
                class="py-4 first:pt-0"
                data-test="daily-report"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-medium tabular-nums">
                            {{ formatCalendarDate(report.report_date) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ report.reporter.name }} ·
                            <span class="tabular-nums">{{
                                formatDateTime(report.created_at)
                            }}</span>
                            <template v-if="report.editor">
                                · diubah oleh {{ report.editor.name }}
                            </template>
                        </p>
                    </div>
                    <Button
                        v-if="report.can_edit"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Ubah laporan ${formatCalendarDate(report.report_date)}`"
                        @click="openDialog(report)"
                    >
                        <Pencil />
                    </Button>
                </div>

                <p class="mt-2 text-sm whitespace-pre-line">
                    {{ report.note }}
                </p>

                <ul v-if="report.links.length > 0" class="mt-2 space-y-1">
                    <li
                        v-for="link in report.links"
                        :key="link"
                        class="min-w-0 text-sm"
                    >
                        <a
                            v-if="safeHttpUrl(link)"
                            :href="safeHttpUrl(link) ?? undefined"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            class="inline-flex max-w-full items-center gap-1 underline underline-offset-2"
                            :title="link"
                        >
                            <ExternalLink
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ linkLabel(link) }}</span>
                        </a>
                        <span v-else class="break-all text-muted-foreground">
                            {{ link }}
                        </span>
                    </li>
                </ul>

                <ul
                    v-if="report.files.length > 0"
                    class="mt-2 divide-y rounded-lg border"
                >
                    <AttachmentRow
                        v-for="file in report.files"
                        :key="file.id"
                        :attachment="file"
                    />
                </ul>
            </li>
        </ul>

        <DailyReportDialog
            v-model:open="dialogOpen"
            :work-order-id="workOrderId"
            :report="editing"
            :settings="settings"
        />
    </section>
</template>
