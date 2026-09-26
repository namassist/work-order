<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { CategoryOption, DepartmentOption, WorkOrder } from '@/types';

const props = defineProps<{
    workOrder: WorkOrder | null;
    /** The requester's department, which the work order belongs to. */
    department: DepartmentOption;
    categories: CategoryOption[];
}>();

const form = useForm({
    title: props.workOrder?.title ?? '',
    description: props.workOrder?.description ?? '',
    work_order_category_id: props.workOrder?.category.id ?? null,
    target_date: props.workOrder?.target_date ?? '',
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        target_date: data.target_date || null,
    })).submit(
        props.workOrder
            ? WorkOrderController.update(props.workOrder.id)
            : WorkOrderController.store(),
    );
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="wo-title">Judul</Label>
            <Input
                id="wo-title"
                v-model="form.title"
                required
                maxlength="255"
                autocomplete="off"
            />
            <InputError :message="form.errors.title" />
        </div>

        <div class="grid gap-2">
            <Label for="wo-description">Deskripsi</Label>
            <Textarea
                id="wo-description"
                v-model="form.description"
                rows="5"
                maxlength="5000"
            />
            <InputError :message="form.errors.description" />
        </div>

        <div class="grid gap-6 md:grid-cols-3">
            <div class="grid gap-2">
                <Label>Departemen</Label>
                <p class="flex h-9 items-center gap-2 text-sm">
                    <span class="font-mono">{{ department.code }}</span>
                    {{ department.name }}
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="wo-category">Kategori</Label>
                <Select v-model="form.work_order_category_id">
                    <SelectTrigger id="wo-category" class="w-full">
                        <SelectValue placeholder="Pilih kategori" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="category in categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            <span class="font-mono">{{ category.code }}</span>
                            {{ category.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.work_order_category_id" />
            </div>

            <div class="grid gap-2">
                <Label for="wo-target-date">
                    Target selesai
                    <span class="font-normal text-muted-foreground">
                        (opsional)
                    </span>
                </Label>
                <Input
                    id="wo-target-date"
                    v-model="form.target_date"
                    type="date"
                />
                <InputError :message="form.errors.target_date" />
            </div>
        </div>

        <p v-if="!workOrder" class="text-sm text-muted-foreground">
            Work order disimpan sebagai draft. Nomor diberikan saat diajukan.
        </p>

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">Simpan</Button>
            <Button variant="outline" as-child>
                <Link
                    :href="
                        workOrder
                            ? WorkOrderController.show(workOrder.id)
                            : WorkOrderController.index()
                    "
                >
                    Batal
                </Link>
            </Button>
        </div>
    </form>
</template>
