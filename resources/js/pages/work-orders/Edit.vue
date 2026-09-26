<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import PagePanel from '@/components/PagePanel.vue';
import WorkOrderForm from '@/components/work-orders/WorkOrderForm.vue';
import type {
    AttachmentPanelData,
    CategoryOption,
    WorkOrder,
    WorkOrderUrgencyOption,
} from '@/types';

defineProps<{
    workOrder: WorkOrder;
    categories: CategoryOption[];
    urgencies: WorkOrderUrgencyOption[];
    attachments: AttachmentPanelData;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Work Order' },
            { title: 'Daftar WO', href: WorkOrderController.index() },
            { title: 'Ubah' },
        ],
    },
});
</script>

<template>
    <Head :title="`Ubah ${workOrder.title}`" />

    <PagePanel title="Ubah work order">
        <template #meta>{{ workOrder.title }}</template>
        <WorkOrderForm
            :work-order="workOrder"
            :department="workOrder.department"
            :categories="categories"
            :urgencies="urgencies"
        />
        <section
            class="border-t px-4 py-6 sm:px-6"
            aria-labelledby="wo-documents-heading"
        >
            <h2 id="wo-documents-heading" class="mb-1 font-medium">Dokumen</h2>
            <p class="mb-4 text-sm text-muted-foreground">
                Berkas langsung tersimpan saat diunggah.
            </p>
            <AttachmentPanel
                :rules="attachments.rules"
                :target="attachments.target"
                :items="attachments.items"
                :can-upload="attachments.can.upload"
                :can-delete="attachments.can.delete"
            />
        </section>
    </PagePanel>
</template>
