<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import PagePanel from '@/components/PagePanel.vue';
import WorkOrderForm from '@/components/work-orders/WorkOrderForm.vue';
import type {
    AttachmentRules,
    CategoryOption,
    DepartmentOption,
} from '@/types';

defineProps<{
    department: DepartmentOption;
    categories: CategoryOption[];
    attachmentRules: AttachmentRules;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Work Order' },
            { title: 'Daftar WO', href: WorkOrderController.index() },
            { title: 'Buat' },
        ],
    },
});
</script>

<template>
    <Head title="Buat work order" />

    <PagePanel title="Buat work order">
        <WorkOrderForm
            :work-order="null"
            :department="department"
            :categories="categories"
            :attachment-rules="attachmentRules"
        />
    </PagePanel>
</template>
