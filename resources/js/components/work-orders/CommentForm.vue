<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import WorkOrderCommentController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderCommentController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * Adds a plain-text comment at the bottom of the work order timeline.
 */
const props = defineProps<{
    workOrderId: number;
    maxLength: number;
}>();

const form = useForm({ body: '' });

const submit = () => {
    form.submit(WorkOrderCommentController.store(props.workOrderId), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <form class="grid gap-2" @submit.prevent="submit">
        <Label for="comment-body">Komentar</Label>
        <Textarea
            id="comment-body"
            v-model="form.body"
            rows="3"
            required
            :maxlength="maxLength"
        />
        <InputError :message="form.errors.body" />
        <div class="flex items-center justify-between gap-2">
            <span class="text-xs text-muted-foreground tabular-nums">
                {{ form.body.length }}/{{ maxLength }}
            </span>
            <Button type="submit" :disabled="form.processing">Kirim</Button>
        </div>
    </form>
</template>
