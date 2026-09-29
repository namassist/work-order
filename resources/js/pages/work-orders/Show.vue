<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { History, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
import { Button } from '@/components/ui/button';
import InvoiceDialog from '@/components/work-orders/InvoiceDialog.vue';
import PaymentDialog from '@/components/work-orders/PaymentDialog.vue';
import TransitionDialog from '@/components/work-orders/TransitionDialog.vue';
import WorkOrderPaymentSection from '@/components/work-orders/WorkOrderPaymentSection.vue';
import WorkOrderStatusBadge from '@/components/work-orders/WorkOrderStatusBadge.vue';
import WorkOrderTimeline from '@/components/work-orders/WorkOrderTimeline.vue';
import WorkOrderUrgency from '@/components/work-orders/WorkOrderUrgency.vue';
import { useCan } from '@/composables/useCan';
import { useFormatDate } from '@/composables/useFormatDate';
import type {
    AttachmentPanelData,
    AttachmentRules,
    PaymentStatusOption,
    TimelineEntry,
    WorkOrder,
    WorkOrderCommentSettings,
    WorkOrderInvoice,
    WorkOrderStatusNote,
    WorkOrderTransition,
} from '@/types';

const props = defineProps<{
    workOrder: WorkOrder;
    timeline: TimelineEntry[];
    /** Only the status changes this user may perform. */
    transitions: WorkOrderTransition[];
    /** Who the work order waits for, when it is not this user's turn. */
    waitingFor: string | null;
    statusNote: WorkOrderStatusNote | null;
    can: {
        update: boolean;
        delete: boolean;
        comment: boolean;
        bill: boolean;
        correctInvoice: boolean;
        confirmPayment: boolean;
    };
    comments: WorkOrderCommentSettings;
    attachments: AttachmentPanelData;
    /** The payment track (FLOW.md §10); null until the work order is closed. */
    paymentStatus: PaymentStatusOption | null;
    /** Why this user may not confirm the payment (segregation of duties). */
    paymentBlockedReason: string | null;
    /** Once Finance billed the closed work order. */
    invoice: WorkOrderInvoice | null;
    /** Invoice and proof of payment, once billed. */
    invoiceAttachments: Partial<
        Record<'invoice' | 'bukti_bayar', AttachmentPanelData>
    >;
    /** Upload rules for the invoice and payment forms. */
    invoiceRules: Record<'invoice' | 'bukti_bayar', AttachmentRules>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Work Order' },
            { title: 'Daftar WO', href: WorkOrderController.index() },
            { title: 'Detail' },
        ],
    },
});

const { formatDateTime, formatCalendarDate } = useFormatDate();
const hasPermission = useCan();

const transitionOpen = ref(false);
const selectedTransition = ref<WorkOrderTransition | null>(null);

const invoiceOpen = ref(false);
const correcting = ref(false);
const paymentOpen = ref(false);

const openTransition = (transition: WorkOrderTransition) => {
    selectedTransition.value = transition;
    transitionOpen.value = true;
};

const openInvoice = (correct: boolean) => {
    correcting.value = correct;
    invoiceOpen.value = true;
};

const historyOpen = ref(false);
const deleteOpen = ref(false);
const deleting = ref(false);

// One primary action per area: the first that moves the work order forward.
const primaryTransition = computed(
    () =>
        props.transitions.find((transition) => !transition.destructive)?.value,
);

const destroy = () => {
    router.visit(WorkOrderController.destroy(props.workOrder.id), {
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            deleteOpen.value = false;
        },
    });
};
</script>

<template>
    <Head :title="`${workOrder.display_number} · ${workOrder.title}`" />

    <PagePanel :title="workOrder.title">
        <template #meta>
            <span class="flex flex-wrap items-center gap-2">
                <span class="font-mono">{{ workOrder.display_number }}</span>
                <WorkOrderStatusBadge :status="workOrder.status" />
            </span>
        </template>

        <ListToolbar
            v-if="
                transitions.length > 0 ||
                waitingFor ||
                can.update ||
                can.delete ||
                hasPermission('activity-log.view')
            "
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="transition in transitions"
                        :key="transition.value"
                        :variant="
                            transition.value === primaryTransition
                                ? 'default'
                                : 'outline'
                        "
                        @click="openTransition(transition)"
                    >
                        {{ transition.label }}
                    </Button>
                    <Button v-if="can.update" variant="outline" as-child>
                        <Link :href="WorkOrderController.edit(workOrder.id)">
                            <Pencil /> Ubah
                        </Link>
                    </Button>
                    <Button
                        v-if="hasPermission('activity-log.view')"
                        variant="ghost"
                        @click="historyOpen = true"
                    >
                        <History /> Riwayat
                    </Button>
                    <Button
                        v-if="can.delete"
                        variant="ghost"
                        size="icon"
                        aria-label="Hapus draft"
                        @click="deleteOpen = true"
                    >
                        <Trash2 />
                    </Button>
                    <p
                        v-if="waitingFor"
                        class="basis-full text-xs text-muted-foreground"
                    >
                        {{ waitingFor }}
                    </p>
                </div>
            </template>
        </ListToolbar>

        <section
            v-if="statusNote"
            class="border-b px-4 py-4 text-sm sm:px-6"
            aria-labelledby="wo-status-note-heading"
        >
            <h2 id="wo-status-note-heading" class="text-muted-foreground">
                {{ statusNote.label }}
            </h2>
            <p class="mt-1 whitespace-pre-line">{{ statusNote.note }}</p>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ statusNote.user }} ·
                <span class="tabular-nums">{{
                    formatDateTime(statusNote.created_at)
                }}</span>
            </p>
        </section>

        <section class="px-4 py-6 sm:px-6" aria-labelledby="wo-detail-heading">
            <h2 id="wo-detail-heading" class="sr-only">Detail</h2>
            <dl
                class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3"
            >
                <div>
                    <dt class="text-muted-foreground">Departemen pemohon</dt>
                    <dd>
                        <span class="font-mono">{{
                            workOrder.requester_department.code
                        }}</span>
                        {{ workOrder.requester_department.name }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Kontak pemohon</dt>
                    <dd>{{ workOrder.requester_name }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Diinput oleh</dt>
                    <dd>{{ workOrder.entered_by.name }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">PIC Work Order</dt>
                    <dd v-if="workOrder.pic_name">{{ workOrder.pic_name }}</dd>
                    <dd v-else class="text-muted-foreground">Tidak diisi</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Departemen tujuan</dt>
                    <dd v-if="workOrder.target_department">
                        <span class="font-mono">{{
                            workOrder.target_department.code
                        }}</span>
                        {{ workOrder.target_department.name }}
                    </dd>
                    <dd v-else class="text-muted-foreground">Tidak diisi</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Kategori</dt>
                    <dd>
                        <span class="font-mono">{{
                            workOrder.category.code
                        }}</span>
                        {{ workOrder.category.name }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Urgensi</dt>
                    <dd>
                        <WorkOrderUrgency :urgency="workOrder.urgency" />
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Target selesai</dt>
                    <dd class="tabular-nums">
                        {{
                            workOrder.target_date
                                ? formatCalendarDate(workOrder.target_date)
                                : '—'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Dibuat</dt>
                    <dd class="tabular-nums">
                        {{ formatDateTime(workOrder.created_at) }}
                    </dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-muted-foreground">Deskripsi</dt>
                    <dd class="whitespace-pre-line">
                        {{ workOrder.description || '—' }}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            class="border-t px-4 py-6 sm:px-6"
            aria-labelledby="wo-documents-heading"
        >
            <h2 id="wo-documents-heading" class="mb-4 font-medium">Dokumen</h2>
            <AttachmentPanel
                :rules="attachments.rules"
                :target="attachments.target"
                :items="attachments.items"
                :can-upload="attachments.can.upload"
                :can-delete="attachments.can.delete"
            />
        </section>

        <WorkOrderPaymentSection
            v-if="paymentStatus"
            :payment-status="paymentStatus"
            :invoice="invoice"
            :attachments="invoiceAttachments"
            :can="can"
            :blocked-reason="paymentBlockedReason"
            @bill="openInvoice(false)"
            @correct="openInvoice(true)"
            @confirm="paymentOpen = true"
        />

        <section
            class="border-t px-4 py-6 sm:px-6"
            aria-labelledby="wo-activity-heading"
        >
            <h2 id="wo-activity-heading" class="mb-4 font-medium">Aktivitas</h2>
            <div class="max-w-3xl">
                <WorkOrderTimeline
                    :entries="timeline"
                    :work-order-id="workOrder.id"
                    :can-comment="can.comment"
                    :comments="comments"
                />
            </div>
        </section>
    </PagePanel>

    <TransitionDialog
        v-model:open="transitionOpen"
        :work-order-id="workOrder.id"
        :display-number="workOrder.display_number"
        :transition="selectedTransition"
    />

    <InvoiceDialog
        v-model:open="invoiceOpen"
        :work-order-id="workOrder.id"
        :display-number="workOrder.display_number"
        :invoice="correcting ? invoice : null"
        :rules="invoiceRules.invoice"
        :files="invoiceAttachments.invoice?.items ?? []"
    />

    <PaymentDialog
        v-if="invoice"
        v-model:open="paymentOpen"
        :work-order-id="workOrder.id"
        :display-number="workOrder.display_number"
        :invoice="invoice"
        :rules="invoiceRules.bukti_bayar"
    />

    <ActivityHistorySheet
        v-if="hasPermission('activity-log.view')"
        v-model:open="historyOpen"
        subject-type="work-order"
        :subject-id="workOrder.id"
        :title="workOrder.display_number"
    />

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Hapus draft?"
        description="Draft akan disembunyikan dari daftar. Pengguna dengan hak pulihkan dapat mengembalikannya."
        confirm-label="Hapus"
        :processing="deleting"
        @confirm="destroy"
    />
</template>
