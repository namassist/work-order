<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { History, Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
import { Button } from '@/components/ui/button';
import TransitionDialog from '@/components/work-orders/TransitionDialog.vue';
import WorkOrderStatusBadge from '@/components/work-orders/WorkOrderStatusBadge.vue';
import WorkOrderTimeline from '@/components/work-orders/WorkOrderTimeline.vue';
import WorkOrderUrgency from '@/components/work-orders/WorkOrderUrgency.vue';
import { useCan } from '@/composables/useCan';
import { useFormatDate } from '@/composables/useFormatDate';
import type {
    AttachmentPanelData,
    TimelineEntry,
    WorkOrder,
    WorkOrderCommentSettings,
    WorkOrderTransition,
} from '@/types';

const props = defineProps<{
    workOrder: WorkOrder;
    timeline: TimelineEntry[];
    transitions: WorkOrderTransition[];
    can: { update: boolean; delete: boolean; comment: boolean };
    comments: WorkOrderCommentSettings;
    attachments: AttachmentPanelData;
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

const openTransition = (transition: WorkOrderTransition) => {
    selectedTransition.value = transition;
    transitionOpen.value = true;
};

const historyOpen = ref(false);
const deleteOpen = ref(false);
const deleting = ref(false);

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
                can.update ||
                can.delete ||
                hasPermission('activity-log.view')
            "
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="(transition, index) in transitions"
                        :key="transition.value"
                        :variant="index === 0 ? 'default' : 'outline'"
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
                </div>
            </template>
        </ListToolbar>

        <section class="px-4 py-6 sm:px-6" aria-labelledby="wo-detail-heading">
            <h2 id="wo-detail-heading" class="sr-only">Detail</h2>
            <dl
                class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3"
            >
                <div>
                    <dt class="text-muted-foreground">Departemen</dt>
                    <dd>
                        <span class="font-mono">{{
                            workOrder.department.code
                        }}</span>
                        {{ workOrder.department.name }}
                    </dd>
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
                    <dt class="text-muted-foreground">Pemohon</dt>
                    <dd>{{ workOrder.requester.name }}</dd>
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
