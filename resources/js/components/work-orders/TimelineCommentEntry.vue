<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import WorkOrderCommentController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderCommentController';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import RowActionsMenu from '@/components/RowActionsMenu.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import { useFormatDate } from '@/composables/useFormatDate';
import type { CommentEntry } from '@/types';

/**
 * A comment in the work order timeline. The body is plain text: rendered
 * through interpolation (never v-html) with its line breaks kept.
 */
const props = defineProps<{
    entry: CommentEntry;
    workOrderId: number;
    maxLength: number;
}>();

const { formatDateTime } = useFormatDate();

const editing = ref(false);
const form = useForm({ body: '' });

const startEditing = () => {
    form.clearErrors();
    form.defaults({ body: props.entry.body ?? '' });
    form.reset();
    editing.value = true;
};

const save = () => {
    form.submit(
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
            <Textarea
                v-model="form.body"
                rows="3"
                required
                :maxlength="maxLength"
                aria-label="Ubah komentar"
            />
            <InputError :message="form.errors.body" />
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="editing = false"
                >
                    Batal
                </Button>
                <Button type="submit" size="sm" :disabled="form.processing">
                    Simpan
                </Button>
            </div>
        </form>

        <p
            v-else
            class="mt-1 text-sm break-words whitespace-pre-line"
            data-test="body"
        >
            {{ entry.body }}
        </p>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Hapus komentar?"
            description='Komentar akan diganti tulisan "Komentar dihapus" di timeline.'
            confirm-label="Hapus"
            :processing="deleting"
            @confirm="destroy"
        />
    </div>
</template>
