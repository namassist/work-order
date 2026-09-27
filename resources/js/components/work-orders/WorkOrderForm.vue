<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
import RequesterAccountPicker from '@/components/work-orders/RequesterAccountPicker.vue';
import WorkOrderUrgency from '@/components/work-orders/WorkOrderUrgency.vue';
import { requesterPayload } from '@/lib/workOrderRequester';
import type { RequesterMode } from '@/lib/workOrderRequester';
import type {
    AttachmentRules,
    CategoryOption,
    DepartmentOption,
    RequesterAccount,
    RequesterCorrection,
    WorkOrder,
    WorkOrderUrgencyOption,
} from '@/types';

const props = defineProps<{
    workOrder: WorkOrder | null;
    /** The requester's department; null when a koordinator picks one (requesterDepartments). */
    department: DepartmentOption | null;
    /** On create by a koordinator: the IC departments to enter the work order for. */
    requesterDepartments?: DepartmentOption[] | null;
    /** On edit by the koordinator who entered this draft: its current requester, to correct. */
    requesterCorrection?: RequesterCorrection | null;
    /** Executor company departments the work order can be addressed to. */
    targetDepartments: DepartmentOption[];
    categories: CategoryOption[];
    urgencies: WorkOrderUrgencyOption[];
    /** On create only: documents are sent with the form. Edit uploads them separately. */
    attachmentRules?: AttachmentRules;
}>();

/** The target select's value while no department is chosen. */
const NO_TARGET = 'none';

/** Create on behalf of IC: the koordinator picks the department and the requester. */
const onBehalf = computed(
    () => !props.workOrder && !!props.requesterDepartments,
);

/** The requester section: on-behalf create, or correcting an on-behalf draft. */
const choosesRequester = computed(
    () => onBehalf.value || !!props.requesterCorrection,
);

const requesterAccount = ref<RequesterAccount | null>(
    props.requesterCorrection?.account ?? null,
);

const form = useForm({
    title: props.workOrder?.title ?? '',
    description: props.workOrder?.description ?? '',
    work_order_category_id: props.workOrder?.category.id ?? null,
    target_department_id: (props.workOrder?.target_department?.id ??
        NO_TARGET) as number | typeof NO_TARGET,
    urgency: props.workOrder?.urgency.value ?? 'normal',
    target_date: props.workOrder?.target_date ?? '',
    attachments: [] as File[],
    requester_department_id: (props.requesterCorrection?.department_id ??
        null) as number | null,
    requester_mode: (props.requesterCorrection?.contact_name
        ? 'contact'
        : 'account') as RequesterMode,
    requester_name: props.requesterCorrection?.contact_name ?? '',
});

const selectedUrgency = computed(() =>
    props.urgencies.find((urgency) => urgency.value === form.urgency),
);

/** requester_id is sent from the picker, not a field of this form. */
const requesterIdError = computed(
    () => (form.errors as Record<string, string | undefined>).requester_id,
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
    form.transform(
        ({
            attachments,
            requester_department_id,
            requester_mode,
            requester_name,
            ...data
        }) => ({
            ...data,
            ...requesterPayload({
                choosesRequester: choosesRequester.value,
                onBehalf: onBehalf.value,
                mode: requester_mode,
                account: requesterAccount.value,
                contactName: requester_name,
                departmentId: requester_department_id,
            }),
            target_date: data.target_date || null,
            target_department_id:
                data.target_department_id === NO_TARGET
                    ? null
                    : data.target_department_id,
            ...(props.workOrder ? {} : { attachments }),
        }),
    ).submit(
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

            <section
                v-if="choosesRequester"
                class="grid gap-4 border-y py-6 md:col-span-2"
                aria-labelledby="wo-requester-heading"
            >
                <div>
                    <h2 id="wo-requester-heading" class="font-medium">
                        Pemohon
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        <template v-if="onBehalf">
                            Anda menginput work order ini atas nama departemen
                            IC. Anda tercatat sebagai penginput, pemohonnya yang
                            dipilih di sini.
                        </template>
                        <template v-else>
                            Perbaiki pemohon draft yang Anda input. Departemen
                            pemohon tidak dapat diubah.
                        </template>
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div v-if="onBehalf" class="grid content-start gap-2">
                        <Label for="wo-requester-department"
                            >Departemen pemohon</Label
                        >
                        <Select v-model="form.requester_department_id">
                            <SelectTrigger
                                id="wo-requester-department"
                                class="w-full"
                            >
                                <SelectValue
                                    placeholder="Pilih departemen IC"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in requesterDepartments"
                                    :key="option.id"
                                    :value="option.id"
                                >
                                    <span class="font-mono">{{
                                        option.code
                                    }}</span>
                                    {{ option.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="form.errors.requester_department_id"
                        />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="wo-requester-mode">Jenis pemohon</Label>
                        <Select v-model="form.requester_mode">
                            <SelectTrigger
                                id="wo-requester-mode"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="account">
                                    Akun pemohon
                                </SelectItem>
                                <SelectItem value="contact">
                                    Nama kontak (tanpa akun)
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.requester_mode" />
                    </div>

                    <div
                        v-if="form.requester_mode === 'account'"
                        class="grid content-start gap-2 md:col-span-2"
                    >
                        <Label for="wo-requester-account">Akun pemohon</Label>
                        <RequesterAccountPicker
                            v-model="requesterAccount"
                            input-id="wo-requester-account"
                            :department-id="
                                onBehalf
                                    ? form.requester_department_id
                                    : (requesterCorrection?.department_id ??
                                      null)
                            "
                        />
                        <InputError :message="requesterIdError" />
                    </div>

                    <div v-else class="grid content-start gap-2">
                        <Label for="wo-requester-name"
                            >Nama kontak pemohon</Label
                        >
                        <Input
                            id="wo-requester-name"
                            v-model="form.requester_name"
                            maxlength="150"
                            autocomplete="off"
                            placeholder="Pak Andi, Maintenance"
                        />
                        <InputError :message="form.errors.requester_name" />
                    </div>
                </div>
            </section>

            <div v-if="department" class="grid content-start gap-2">
                <Label>Departemen pemohon</Label>
                <p class="flex h-9 items-center gap-2 text-sm">
                    <span class="font-mono">{{ department.code }}</span>
                    {{ department.name }}
                </p>
            </div>

            <div class="grid content-start gap-2">
                <Label for="wo-target-department">Departemen tujuan</Label>
                <Select v-model="form.target_department_id">
                    <SelectTrigger id="wo-target-department" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="NO_TARGET"
                            >Belum dipilih</SelectItem
                        >
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
                    Departemen pelaksana yang mengerjakan. Wajib dipilih sebelum
                    diajukan.
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
