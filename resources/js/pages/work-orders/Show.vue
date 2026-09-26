<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { History, Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import StatusTimeline from '@/components/work-orders/StatusTimeline.vue';
import TransitionDialog from '@/components/work-orders/TransitionDialog.vue';
import WorkOrderStatusBadge from '@/components/work-orders/WorkOrderStatusBadge.vue';
import { useCan } from '@/composables/useCan';
import { useFormatDate } from '@/composables/useFormatDate';
import type {
    StatusHistoryEntry,
    WorkOrder,
    WorkOrderTransition,
} from '@/types';

const props = defineProps<{
    workOrder: WorkOrder;
    timeline: StatusHistoryEntry[];
    transitions: WorkOrderTransition[];
    can: { update: boolean; delete: boolean };
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

    <div class="flex flex-1 flex-col gap-4 p-4">
        <PageHeader :title="workOrder.title">
            <template #description>
                <span class="flex flex-wrap items-center gap-2">
                    <span class="font-mono">{{
                        workOrder.display_number
                    }}</span>
                    <WorkOrderStatusBadge :status="workOrder.status" />
                </span>
            </template>
            <template #actions>
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
                    v-if="can.delete"
                    variant="ghost"
                    size="icon"
                    aria-label="Hapus draft"
                    @click="deleteOpen = true"
                >
                    <Trash2 />
                </Button>
                <Button
                    v-if="hasPermission('activity-log.view')"
                    variant="ghost"
                    @click="historyOpen = true"
                >
                    <History /> Riwayat
                </Button>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <section
                class="rounded-2xl border bg-card p-6 lg:col-span-2"
                aria-labelledby="wo-detail-heading"
            >
                <h2 id="wo-detail-heading" class="sr-only">Detail</h2>
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
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
                    <div class="sm:col-span-2">
                        <dt class="text-muted-foreground">Deskripsi</dt>
                        <dd class="whitespace-pre-line">
                            {{ workOrder.description || '—' }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section
                class="rounded-2xl border bg-card p-6"
                aria-labelledby="wo-timeline-heading"
            >
                <h2 id="wo-timeline-heading" class="mb-4 font-medium">
                    Status
                </h2>
                <StatusTimeline :entries="timeline" />
            </section>
        </div>
    </div>

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
