<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import RegistrationController from '@/actions/App/Http/Controllers/Admin/RegistrationController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Registration } from '@/types';

const REASON_MAX_LENGTH = 500;

const props = defineProps<{
    registration: Registration | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({ reason: '' });

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.reset();
    }
});

const submit = () => {
    if (!props.registration) {
        return;
    }

    form.submit(RegistrationController.reject(props.registration.id), {
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
                    <DialogTitle>Tolak pendaftaran</DialogTitle>
                    <DialogDescription v-if="registration">
                        {{ registration.name }} ({{ registration.email }})
                        melihat alasan ini setelah login. Pendaftaran yang
                        ditolak masih dapat disetujui nanti.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="reject-reason">Alasan</Label>
                    <Textarea
                        id="reject-reason"
                        v-model="form.reason"
                        rows="4"
                        required
                        :maxlength="REASON_MAX_LENGTH"
                        placeholder="Mis. email bukan milik karyawan departemen ini."
                    />
                    <InputError :message="form.errors.reason" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Batal</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="form.processing || form.reason.trim() === ''"
                    >
                        Tolak
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
