<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import WorkOrderCommentController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderCommentController';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import AttachmentRow from '@/components/attachments/AttachmentRow.vue';
import InputError from '@/components/InputError.vue';
import RowActionsMenu from '@/components/RowActionsMenu.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import CommentBody from '@/components/work-orders/CommentBody.vue';
import CommentEditor from '@/components/work-orders/CommentEditor.vue';
import { useFormatDate } from '@/composables/useFormatDate';
import type {
    Attachment,
    CommentEntry,
    WorkOrderCommentSettings,
} from '@/types';

/**
 * A comment in the work order timeline: its sanitized HTML body (see
 * CommentBody) and its documents, edited in the same editor it was written
 * in.
 */
const props = defineProps<{
    entry: CommentEntry;
    workOrderId: number;
    settings: WorkOrderCommentSettings;
}>();

const { formatDateTime } = useFormatDate();

const editing = ref(false);
const form = useForm({ body: '', attachments: [] as string[] });
const documents = ref<Attachment[]>([]);
const uploading = ref(false);

const startEditing = () => {
    form.clearErrors();
    form.defaults({ body: props.entry.body ?? '', attachments: [] });
    form.reset();
    documents.value = [...props.entry.attachments];
    editing.value = true;
};

const save = () => {
    form.transform((data) => ({
        ...data,
        attachments: documents.value.map((document) => document.id),
    })).submit(
        WorkOrderCommentController.update([props.workOrderId, props.entry.id]),
        {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = false;
            },
        },
    );
};

const deleteOpen = ref(false);
const deleteDescription = computed(() =>
    props.entry.attachments.length > 0 || props.entry.body?.includes('<img')
        ? 'Komentar akan diganti tulisan "Komentar dihapus" di timeline, dan gambar serta lampirannya dihapus permanen.'
        : 'Komentar akan diganti tulisan "Komentar dihapus" di timeline.',
);
const deleting = ref(false);

const destroy = () => {
    router.visit(
        WorkOrderCommentController.destroy([props.workOrderId, props.entry.id]),
        {
            preserveScroll: true,
            onStart: () => (deleting.value = true),
            onFinish: () => {
                deleting.value = false;
                deleteOpen.value = false;
            },
        },
    );
};
</script>

<template>
    <div>
        <div class="flex items-start justify-between gap-2">
            <p class="text-sm text-muted-foreground">
                <span class="font-medium text-foreground">{{
                    entry.user.name
                }}</span>
                &middot;
                <time :datetime="entry.created_at" class="tabular-nums">
                    {{ formatDateTime(entry.created_at) }}
                </time>
                <span v-if="entry.edited && !entry.deleted" data-test="edited">
                    (diedit)</span
                >
            </p>
            <!-- Negative margin keeps the icon button from pushing the body down. -->
            <div
                v-if="!editing && (entry.can.update || entry.can.delete)"
                class="-my-2 shrink-0"
            >
                <RowActionsMenu :label="`Aksi komentar ${entry.user.name}`">
                    <DropdownMenuItem
                        v-if="entry.can.update"
                        @select="startEditing"
                    >
                        <Pencil /> Ubah
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="entry.can.delete"
                        variant="destructive"
                        @select="deleteOpen = true"
                    >
                        <Trash2 /> Hapus
                    </DropdownMenuItem>
                </RowActionsMenu>
            </div>
        </div>

        <p
            v-if="entry.deleted"
            class="mt-1 text-sm text-muted-foreground italic"
            data-test="deleted"
        >
            Komentar dihapus
        </p>

        <form
            v-else-if="editing"
            class="mt-2 grid gap-2"
            @submit.prevent="save"
        >
            <CommentEditor
                v-model="form.body"
                v-model:documents="documents"
                v-model:busy="uploading"
                :work-order-id="workOrderId"
                :settings="settings"
                :input-id="`comment-${entry.id}-body`"
                label="Ubah komentar"
                :invalid="!!form.errors.body"
            />
            <InputError :message="form.errors.body" />
            <InputError :message="form.errors.attachments" />
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="editing = false"
                >
                    Batal
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="form.processing || uploading"
                >
                    Simpan
                </Button>
            </div>
        </form>

        <template v-else>
            <CommentBody v-if="entry.body" :html="entry.body" class="mt-1" />
            <ul
                v-if="entry.attachments.length"
                class="mt-2 divide-y rounded-lg border"
                aria-label="Lampiran komentar"
            >
                <AttachmentRow
                    v-for="attachment in entry.attachments"
                    :key="attachment.id"
                    :attachment="attachment"
                />
            </ul>
        </template>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Hapus komentar?"
            :description="deleteDescription"
            confirm-label="Hapus"
            :processing="deleting"
            @confirm="destroy"
        />
    </div>
</template>
