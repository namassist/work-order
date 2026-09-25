<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
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
import type { Department } from '@/types';

const props = defineProps<{
    department: Department | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
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
        code: props.department?.code ?? '',
        name: props.department?.name ?? '',
        is_active: props.department?.is_active ?? true,
    });
    form.reset();
});

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
