<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import PageHeader from '@/components/PageHeader.vue';
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

    <div class="flex flex-1 flex-col gap-4 p-4">
        <PageHeader
            class="max-w-3xl"
            title="Buat work order"
            description="Work order tercatat atas nama departemen Anda."
        />
        <div class="max-w-3xl rounded-2xl border bg-card p-6">
            <WorkOrderForm
                :work-order="null"
                :department="department"
                :categories="categories"
                :attachment-rules="attachmentRules"
            />
        </div>
    </div>
</template>
