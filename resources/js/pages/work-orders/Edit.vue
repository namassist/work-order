<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import PageHeader from '@/components/PageHeader.vue';
import WorkOrderForm from '@/components/work-orders/WorkOrderForm.vue';
import type { CategoryOption, WorkOrder } from '@/types';

defineProps<{
    workOrder: WorkOrder;
    categories: CategoryOption[];
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
    </div>
</template>
