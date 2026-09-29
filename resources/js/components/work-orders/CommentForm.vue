<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import WorkOrderCommentController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderCommentController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import CommentEditor from '@/components/work-orders/CommentEditor.vue';
import type { Attachment, WorkOrderCommentSettings } from '@/types';

/**
 * Adds a rich-text comment, with inline images and documents, at the bottom
 * of the work order timeline.
 */
const props = defineProps<{
    workOrderId: number;
    settings: WorkOrderCommentSettings;
}>();

const form = useForm({ body: '', attachments: [] as string[] });
const documents = ref<Attachment[]>([]);
const uploading = ref(false);

const submit = () => {
    form.transform((data) => ({
        ...data,
        attachments: documents.value.map((document) => document.id),
    })).submit(WorkOrderCommentController.store(props.workOrderId), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            documents.value = [];
        },
    });
};
</script>

<template>
    <form class="grid gap-2" @submit.prevent="submit">
        <p id="comment-body-label" class="text-sm font-medium">Komentar</p>
        <CommentEditor
            v-model="form.body"
            v-model:documents="documents"
            v-model:busy="uploading"
            :work-order-id="workOrderId"
            :settings="settings"
            input-id="comment-body"
            label="Komentar"
            :invalid="!!form.errors.body"
        />
        <InputError :message="form.errors.body" />
        <InputError :message="form.errors.attachments" />
        <div class="flex justify-end">
            <Button type="submit" :disabled="form.processing || uploading">
                Kirim
            </Button>
        </div>
    </form>
</template>
