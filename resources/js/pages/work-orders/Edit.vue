<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import PageHeader from '@/components/PageHeader.vue';
import WorkOrderForm from '@/components/work-orders/WorkOrderForm.vue';
import type { AttachmentPanelData, CategoryOption, WorkOrder } from '@/types';

defineProps<{
    workOrder: WorkOrder;
    categories: CategoryOption[];
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

    <div class="flex flex-1 flex-col gap-4 p-4">
        <PageHeader
            class="max-w-3xl"
            title="Ubah work order"
            :description="workOrder.title"
        />
        <div class="max-w-3xl rounded-2xl border bg-card p-6">
            <WorkOrderForm
                :work-order="workOrder"
                :department="workOrder.department"
                :categories="categories"
            />
        </div>
        <section
            class="max-w-3xl rounded-2xl border bg-card p-6"
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
    </div>
</template>
