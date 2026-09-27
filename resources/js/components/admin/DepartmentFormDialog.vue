<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DepartmentController from '@/actions/App/Http/Controllers/Admin/DepartmentController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { CompanyOption, Department } from '@/types';

const props = defineProps<{
    department: Department | null;
    companies: CompanyOption[];
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    company_id: null as number | null,
    code: '',
    name: '',
    is_active: true,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        company_id: props.department?.company_id ?? null,
        code: props.department?.code ?? '',
        name: props.department?.name ?? '',
        is_active: props.department?.is_active ?? true,
    });
    form.reset();
});

// Active companies, plus the one the department already has.
const selectableCompanies = computed(() =>
    props.companies.filter(
        (company) =>
            (company.is_active && !company.deleted) ||
            company.id === props.department?.company_id,
    ),
);

const submit = () => {
    const action = props.department
        ? DepartmentController.update(props.department.id)
        : DepartmentController.store();

    form.submit(action, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            department ? 'Ubah departemen' : 'Tambah departemen'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Kode dipakai di nomor dokumen dan harus unik.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="department-company">Perusahaan</Label>
                    <Select v-model="form.company_id">
                        <SelectTrigger id="department-company" class="w-full">
                            <SelectValue placeholder="Pilih perusahaan" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="company in selectableCompanies"
                                :key="company.id"
                                :value="company.id"
                            >
                                {{ company.name }}
                                <span class="text-muted-foreground">
                                    ·
                                    {{
                                        company.is_client
                                            ? 'Klien'
                                            : 'Pelaksana'
                                    }}
                                </span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        Tidak dapat dipindah setelah departemen memiliki
                        pengguna atau work order.
                    </p>
                    <InputError :message="form.errors.company_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="department-code">Kode</Label>
                    <Input
                        id="department-code"
                        v-model="form.code"
                        class="font-mono uppercase"
                        maxlength="20"
                        required
                        autocomplete="off"
                        placeholder="FIN"
                    />
                    <InputError :message="form.errors.code" />
                </div>

                <div class="grid gap-2">
                    <Label for="department-name">Nama</Label>
                    <Input
                        id="department-name"
                        v-model="form.name"
                        required
                        placeholder="Keuangan"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox id="department-active" v-model="form.is_active" />
                    <Label for="department-active">Aktif</Label>
                    <InputError :message="form.errors.is_active" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Batal</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="form.processing">
                        Simpan
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
