<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import WorkOrderTransitionController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderTransitionController';
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
import type { WorkOrderTransition } from '@/types';

/**
 * Confirms a status change, with a note (required when the transition says so).
 */
const props = defineProps<{
    workOrderId: number;
    displayNumber: string;
    transition: WorkOrderTransition | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({ status: '', note: '' });

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({ status: props.transition?.value ?? '', note: '' });
    form.reset();
});

const submit = () => {
    form.submit(WorkOrderTransitionController.store(props.workOrderId), {
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
                        {{ transition?.label }} {{ displayNumber }}?
                    </DialogTitle>
                    <DialogDescription>
                        Perubahan status tercatat di riwayat work order.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="transition-note">
                        Catatan
                        <span
                            v-if="!transition?.requires_note"
                            class="font-normal text-muted-foreground"
                        >
                            (opsional)
                        </span>
                    </Label>
                    <Textarea
                        id="transition-note"
                        v-model="form.note"
                        rows="3"
                        :required="transition?.requires_note"
                    />
                    <InputError :message="form.errors.note" />
                    <InputError :message="form.errors.status" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :variant="
                            transition?.tone === 'destructive'
                                ? 'destructive'
                                : 'default'
                        "
                        :disabled="form.processing"
                    >
                        {{ transition?.label }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
