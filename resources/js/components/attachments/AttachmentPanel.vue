<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Download,
    Eye,
    File as FileIcon,
    FileImage,
    FileSpreadsheet,
    FileText,
    Paperclip,
    Trash2,
    Upload,
    X,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import AttachmentController from '@/actions/App/Http/Controllers/Attachments/AttachmentController';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useFormatDate } from '@/composables/useFormatDate';
import { attachmentProblem } from '@/lib/attachments';
import { formatFileSize } from '@/lib/format';
import type { Attachment, AttachmentPanelData, AttachmentRules } from '@/types';

/**
 * Attachments of one collection. With a `target` (a saved record) files
 * upload at once and the list comes from the server. Without one (a create
 * form) files are held in v-model:pending and sent with the parent form,
 * which passes its upload `progress` and `error` back in.
 */
const props = withDefaults(
    defineProps<{
        rules: AttachmentRules;
        target?: AttachmentPanelData['target'];
        items?: Attachment[];
        canUpload: boolean;
        canDelete?: boolean;
        progress?: number | null;
        error?: string;
    }>(),
    {
        target: undefined,
        items: () => [],
        canDelete: false,
        progress: null,
        error: undefined,
    },
);

const pending = defineModel<File[]>('pending', { default: () => [] });

const { formatDateTime } = useFormatDate();

const isLive = computed(() => props.target !== undefined);
const input = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const problems = ref<string[]>([]);
const queue = ref<File[]>([]);
const uploading = ref<{ name: string; percentage: number } | null>(null);

const count = computed(() =>
    isLive.value
        ? props.items.length + queue.value.length + (uploading.value ? 1 : 0)
        : pending.value.length,
);
const full = computed(() => count.value >= props.rules.max_files);
const limits = computed(
    () =>
        `${props.rules.type_list}. Maks. ${formatFileSize(props.rules.max_size_kb * 1024)} per berkas, hingga ${props.rules.max_files} berkas.`,
);
const currentProgress = computed(() =>
    isLive.value ? (uploading.value?.percentage ?? null) : props.progress,
);

const ICONS: Record<string, LucideIcon> = {
    pdf: FileText,
    docx: FileText,
    xlsx: FileSpreadsheet,
    jpg: FileImage,
    png: FileImage,
    webp: FileImage,
};

const iconFor = (name: string, extension?: string | null): LucideIcon =>
    ICONS[extension ?? name.split('.').pop()?.toLowerCase() ?? ''] ?? FileIcon;

const addFiles = (files: FileList | null | undefined) => {
    problems.value = [];

    const accepted: File[] = [];

    for (const file of Array.from(files ?? [])) {
        const problem = attachmentProblem(file, props.rules);

        if (problem) {
            problems.value.push(problem);
        } else {
            accepted.push(file);
        }
    }

    const room = Math.max(props.rules.max_files - count.value, 0);

    if (accepted.length > room) {
        problems.value.push(
            `Maksimal ${props.rules.max_files} berkas; ${accepted.length - room} berkas tidak ditambahkan.`,
        );
        accepted.splice(room);
    }

    if (isLive.value) {
        queue.value = [...queue.value, ...accepted];

        if (!uploading.value) {
            uploadNext();
        }
    } else {
        pending.value = [...pending.value, ...accepted];
    }
};

const uploadNext = () => {
    const [file, ...rest] = queue.value;
    queue.value = rest;

    if (!file || !props.target) {
        uploading.value = null;

        return;
    }

    uploading.value = { name: file.name, percentage: 0 };

    router.post(
        AttachmentController.store.url({
            attachableType: props.target.type,
            attachableId: props.target.id,
            collection: props.target.collection,
        }),
        { file },
        {
            forceFormData: true,
            preserveScroll: true,
            onProgress: (event) => {
                if (uploading.value) {
                    uploading.value.percentage = event?.percentage ?? 0;
                }
            },
            onError: (errors) => {
                problems.value.push(
                    `${file.name}: ${errors.file ?? 'gagal diunggah.'}`,
                );
            },
            onFinish: uploadNext,
        },
    );
};

const onDrop = (event: DragEvent) => {
    dragging.value = false;

    if (props.canUpload && !full.value) {
        addFiles(event.dataTransfer?.files);
    }
};

const onPick = (event: Event) => {
    const target = event.target as HTMLInputElement;
    addFiles(target.files);
    target.value = '';
};

const removePending = (index: number) => {
    pending.value = pending.value.filter((_, position) => position !== index);
};

const toDelete = ref<Attachment | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);

const confirmDelete = (attachment: Attachment) => {
    toDelete.value = attachment;
    deleteOpen.value = true;
};

const destroy = () => {
    if (!toDelete.value) {
        return;
    }

    router.delete(AttachmentController.destroy.url(toDelete.value.id), {
        preserveScroll: true,
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            deleteOpen.value = false;
        },
    });
};
</script>

<template>
    <div class="space-y-4">
        <div
            v-if="canUpload"
            class="flex flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-center transition-colors duration-150 ease-out motion-reduce:transition-none"
            :class="dragging ? 'border-ring bg-muted' : 'border-border'"
            @dragenter.prevent="dragging = !full"
            @dragover.prevent="dragging = !full"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <Upload class="size-5 text-muted-foreground" aria-hidden="true" />
            <p class="text-sm">
                <template v-if="full">
                    Batas {{ rules.max_files }} berkas tercapai.
                </template>
                <template v-else>
                    Seret berkas ke sini atau
                    <Button
                        type="button"
                        variant="link"
                        class="h-auto p-0"
                        @click="input?.click()"
                    >
                        pilih berkas
                    </Button>
                </template>
            </p>
            <p class="text-xs text-muted-foreground">{{ limits }}</p>
            <input
                ref="input"
                type="file"
                class="hidden"
                multiple
                :accept="rules.accept"
                @change="onPick"
            />
        </div>

        <div
            v-if="currentProgress !== null"
            class="space-y-1"
            role="status"
            aria-live="polite"
        >
            <p class="text-xs text-muted-foreground">
                Mengunggah{{ uploading ? ` ${uploading.name}` : '' }}…
                <span class="tabular-nums">{{ currentProgress }}%</span>
            </p>
            <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full bg-primary transition-[width] duration-150 ease-out motion-reduce:transition-none"
                    :style="{ width: `${currentProgress}%` }"
                />
            </div>
        </div>

        <div v-if="problems.length || error" aria-live="polite">
            <InputError
                v-for="problem in problems"
                :key="problem"
                :message="problem"
            />
            <InputError :message="error" />
        </div>

        <ul v-if="isLive && items.length" class="divide-y rounded-lg border">
            <li
                v-for="attachment in items"
                :key="attachment.id"
                class="flex items-center gap-3 px-3 py-2"
            >
                <component
                    :is="iconFor(attachment.name, attachment.extension)"
                    class="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm" :title="attachment.name">
                        {{ attachment.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <span class="tabular-nums">{{
                            formatFileSize(attachment.size)
                        }}</span>
                        <template v-if="attachment.uploader">
                            · {{ attachment.uploader.name }}
                        </template>
                        ·
                        <span class="tabular-nums">{{
                            formatDateTime(attachment.created_at)
                        }}</span>
                    </p>
                </div>
                <div class="flex shrink-0 items-center">
                    <Button
                        v-if="attachment.previewable"
                        variant="ghost"
                        size="icon"
                        as-child
                    >
                        <a
                            :href="AttachmentController.show.url(attachment.id)"
                            target="_blank"
                            rel="noopener noreferrer"
                            :aria-label="`Pratinjau ${attachment.name}`"
                        >
                            <Eye />
                        </a>
                    </Button>
                    <Button variant="ghost" size="icon" as-child>
                        <a
                            :href="
                                AttachmentController.show.url(attachment.id, {
                                    query: { download: 1 },
                                })
                            "
                            :aria-label="`Unduh ${attachment.name}`"
                        >
                            <Download />
                        </a>
                    </Button>
                    <Button
                        v-if="canDelete"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Hapus ${attachment.name}`"
                        @click="confirmDelete(attachment)"
                    >
                        <Trash2 />
                    </Button>
                </div>
            </li>
        </ul>

        <ul
            v-else-if="!isLive && pending.length"
            class="divide-y rounded-lg border"
        >
            <li
                v-for="(file, index) in pending"
                :key="`${file.name}-${index}`"
                class="flex items-center gap-3 px-3 py-2"
            >
                <component
                    :is="iconFor(file.name)"
                    class="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm" :title="file.name">
                        {{ file.name }}
                    </p>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ formatFileSize(file.size) }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="`Batalkan ${file.name}`"
                    @click="removePending(index)"
                >
                    <X />
                </Button>
            </li>
        </ul>

        <p
            v-else-if="isLive && !canUpload"
            class="flex items-center gap-2 text-sm text-muted-foreground"
        >
            <Paperclip class="size-4" aria-hidden="true" />
            Belum ada lampiran.
        </p>

        <ConfirmDialog
            v-if="canDelete"
            v-model:open="deleteOpen"
            title="Hapus lampiran?"
            :description="`${toDelete?.name ?? 'Berkas'} akan dihapus permanen.`"
            confirm-label="Hapus"
            :processing="deleting"
            @confirm="destroy"
        />
    </div>
</template>
