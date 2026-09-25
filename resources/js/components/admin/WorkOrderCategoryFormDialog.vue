<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import WorkOrderCategoryController from '@/actions/App/Http/Controllers/Admin/WorkOrderCategoryController';
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
import { Textarea } from '@/components/ui/textarea';
import type { WorkOrderCategory } from '@/types';

const props = defineProps<{
    category: WorkOrderCategory | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    code: '',
    name: '',
    description: '',
    is_active: true,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        code: props.category?.code ?? '',
        name: props.category?.name ?? '',
        description: props.category?.description ?? '',
        is_active: props.category?.is_active ?? true,
    });
    form.reset();
});

const submit = () => {
    const action = props.category
        ? WorkOrderCategoryController.update(props.category.id)
        : WorkOrderCategoryController.store();

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
                        {{ category ? 'Ubah kategori' : 'Tambah kategori' }}
                    </DialogTitle>
                    <DialogDescription>
                        Kategori mengelompokkan work order. Kode harus unik.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="category-code">Kode</Label>
                    <Input
                        id="category-code"
                        v-model="form.code"
                        class="font-mono uppercase"
                        maxlength="20"
                        required
                        autocomplete="off"
                        placeholder="LST"
                    />
                    <InputError :message="form.errors.code" />
                </div>

                <div class="grid gap-2">
                    <Label for="category-name">Nama</Label>
                    <Input
                        id="category-name"
                        v-model="form.name"
                        required
                        placeholder="Listrik"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="category-description">Deskripsi</Label>
                    <Textarea
                        id="category-description"
                        v-model="form.description"
                        maxlength="1000"
                        rows="3"
                        placeholder="Opsional"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox id="category-active" v-model="form.is_active" />
                    <Label for="category-active">Aktif</Label>
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
