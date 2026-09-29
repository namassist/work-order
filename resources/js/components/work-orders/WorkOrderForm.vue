<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import AttachmentPanel from '@/components/attachments/AttachmentPanel.vue';
import FormFooter from '@/components/FormFooter.vue';
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
import WorkOrderUrgency from '@/components/work-orders/WorkOrderUrgency.vue';
import type {
    AttachmentRules,
    CategoryOption,
    DepartmentOption,
    WorkOrder,
    WorkOrderUrgencyOption,
} from '@/types';

const props = defineProps<{
    workOrder: WorkOrder | null;
    /**
     * The IC departments to enter the work order for; null once it was
     * submitted, since its number carries the department's code.
     */
    requesterDepartments: DepartmentOption[] | null;
    /** Executor company departments, for the informational target. */
    targetDepartments: DepartmentOption[];
    categories: CategoryOption[];
    urgencies: WorkOrderUrgencyOption[];
    /** On create only: documents are sent with the form. Edit uploads them separately. */
    attachmentRules?: AttachmentRules;
}>();

/** The target select's value while no department is chosen. */
const NO_TARGET = 'none';

const form = useForm({
    title: props.workOrder?.title ?? '',
    description: props.workOrder?.description ?? '',
    work_order_category_id: props.workOrder?.category.id ?? null,
    requester_department_id: (props.workOrder?.requester_department.id ??
        null) as number | null,
    requester_name: props.workOrder?.requester_name ?? '',
    pic_name: props.workOrder?.pic_name ?? '',
    target_department_id: (props.workOrder?.target_department?.id ??
        NO_TARGET) as number | typeof NO_TARGET,
    urgency: props.workOrder?.urgency.value ?? 'normal',
    target_date: props.workOrder?.target_date ?? '',
    attachments: [] as File[],
});

const selectedUrgency = computed(() =>
    props.urgencies.find((urgency) => urgency.value === form.urgency),
);

/** Errors on the list ("attachments") or on one file ("attachments.0"). */
const attachmentsError = computed(
    () =>
        Object.entries(form.errors as Record<string, string | undefined>)
            .filter(([key]) => key.startsWith('attachments'))
            .map(([, message]) => message)
            .join(' ') || undefined,
);

const submit = () => {
    form.transform(({ attachments, requester_department_id, ...data }) => ({
        ...data,
        // The server refuses the department once the work order was submitted.
        ...(props.requesterDepartments ? { requester_department_id } : {}),
        pic_name: data.pic_name || null,
        target_date: data.target_date || null,
        target_department_id:
            data.target_department_id === NO_TARGET
                ? null
                : data.target_department_id,
        ...(props.workOrder ? {} : { attachments }),
    })).submit(
        props.workOrder
            ? WorkOrderController.update(props.workOrder.id)
            : WorkOrderController.store(),
    );
};
</script>

<template>
    <form @submit.prevent="submit">
        <div class="grid gap-6 px-4 py-6 sm:px-6 md:grid-cols-2">
            <div class="grid content-start gap-2">
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

            <div class="grid content-start gap-2">
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

            <div class="grid content-start gap-2">
                <Label
                    :for="
                        requesterDepartments
                            ? 'wo-requester-department'
                            : undefined
                    "
                    >Departemen pemohon (IC)</Label
                >
                <Select
                    v-if="requesterDepartments"
                    v-model="form.requester_department_id"
                >
                    <SelectTrigger id="wo-requester-department" class="w-full">
                        <SelectValue placeholder="Pilih departemen IC" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in requesterDepartments"
                            :key="option.id"
                            :value="option.id"
                        >
                            <span class="font-mono">{{ option.code }}</span>
                            {{ option.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <template v-else-if="workOrder">
                    <p class="flex h-9 items-center gap-2 text-sm">
                        <span class="font-mono">{{
                            workOrder.requester_department.code
                        }}</span>
                        {{ workOrder.requester_department.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Tidak dapat diubah setelah diajukan: nomor work order
                        memuat kodenya.
                    </p>
                </template>
                <InputError :message="form.errors.requester_department_id" />
            </div>

            <div class="grid content-start gap-2">
                <Label for="wo-requester-name">Kontak pemohon</Label>
                <Input
                    id="wo-requester-name"
                    v-model="form.requester_name"
                    required
                    maxlength="150"
                    autocomplete="off"
                    placeholder="Pak Andi, Produksi"
                />
                <p class="text-xs text-muted-foreground">
                    Orang IC yang mengajukan permintaan.
                </p>
                <InputError :message="form.errors.requester_name" />
            </div>

            <div class="grid content-start gap-2">
                <Label for="wo-pic-name">
                    PIC Work Order
                    <span class="font-normal text-muted-foreground">
                        (opsional)
                    </span>
                </Label>
                <Input
                    id="wo-pic-name"
                    v-model="form.pic_name"
                    maxlength="150"
                    autocomplete="off"
                />
                <p class="text-xs text-muted-foreground">
                    Staf Unggul yang menerima permintaan dari IC.
                </p>
                <InputError :message="form.errors.pic_name" />
            </div>

            <div class="grid content-start gap-2">
                <Label for="wo-target-department">
                    Departemen tujuan
                    <span class="font-normal text-muted-foreground">
                        (opsional)
                    </span>
                </Label>
                <Select v-model="form.target_department_id">
                    <SelectTrigger id="wo-target-department" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="NO_TARGET">Tidak diisi</SelectItem>
                        <SelectItem
                            v-for="target in targetDepartments"
                            :key="target.id"
                            :value="target.id"
                        >
                            <span class="font-mono">{{ target.code }}</span>
                            {{ target.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-xs text-muted-foreground">
                    Hanya informasi; tidak menentukan siapa yang memproses.
                </p>
                <InputError :message="form.errors.target_department_id" />
            </div>

            <div class="grid content-start gap-2">
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

            <div class="grid content-start gap-2">
                <Label for="wo-urgency">Urgensi</Label>
                <Select v-model="form.urgency">
                    <SelectTrigger id="wo-urgency" class="w-full">
                        <!-- SelectValue shows only the label; urgency always shows its icon too. -->
                        <WorkOrderUrgency
                            v-if="selectedUrgency"
                            :urgency="selectedUrgency"
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="urgency in urgencies"
                            :key="urgency.value"
                            :value="urgency.value"
                        >
                            <WorkOrderUrgency :urgency="urgency" />
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.urgency" />
            </div>

            <div class="grid gap-2 md:col-span-2">
                <Label for="wo-description">Deskripsi</Label>
                <Textarea
                    id="wo-description"
                    v-model="form.description"
                    rows="5"
                    maxlength="5000"
                />
                <InputError :message="form.errors.description" />
            </div>

            <div
                v-if="!workOrder && attachmentRules"
                class="grid gap-2 md:col-span-2"
            >
                <Label>
                    Dokumen
                    <span class="font-normal text-muted-foreground">
                        (opsional)
                    </span>
                </Label>
                <AttachmentPanel
                    v-model:pending="form.attachments"
                    :rules="attachmentRules"
                    :can-upload="!form.processing"
                    :progress="form.progress?.percentage ?? null"
                    :error="attachmentsError"
                />
            </div>

            <p
                v-if="!workOrder"
                class="text-sm text-muted-foreground md:col-span-2"
            >
                Work order disimpan sebagai draft. Nomor diberikan saat
                diajukan.
            </p>
        </div>

        <FormFooter>
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
        </FormFooter>
    </form>
</template>
